<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\PaymentReconciliationDiff;
use App\Models\PaymentReconciliationRun;
use App\Models\SysOperationLog;
use App\Services\Common\OperationLogService;
use App\Services\Payment\Dto\ChannelTransaction;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 支付渠道日终对账引擎（A7-支付渠道对账）
 *
 * 逐渠道把「渠道侧交易流水」与「本地 success 支付单」比对，分类下列差异并固化成工单：
 * - MISSING_LOCAL       ：渠道账单有、本地无对应支付单（漏单 / 渠道长款）
 * - MISSING_CHANNEL     ：本地 success、渠道侧无记录或状态非成功（本地短款 / 资金风险）
 * - AMOUNT_MISMATCH     ：双方都有但金额不一致（长短款）
 * - DUPLICATE_CALLBACK  ：同一渠道交易号多次成功回调（重复回调）
 *
 * 设计对齐 WMS 库存对账（WmsReconcileService）：只记录差异、不自动改支付单；
 * 部分唯一索引保证每日重跑不会把同一差异重复开单；已处置（resolved/ignored）不重开。
 *
 * 渠道侧来源：优先 BillReconciliationProvider（真实账单）；不支持/失败时降级 LogReconciliationProvider。
 */
class PaymentReconcileService
{
    public function __construct(
        private readonly BillReconciliationProvider $billProvider,
        private readonly LogReconciliationProvider $logProvider,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 执行指定日期的对账（默认比对全部在线渠道 + 当日有本地成功支付单的渠道）。
     *
     * @param  string|null  $date      YYYY-MM-DD，默认昨天
     * @param  array|null   $channels  限定渠道；null 时自动决定
     * @return array{date:string, runs:array, total_runs:int, total_diff:int}
     */
    public function run(?string $date = null, ?array $channels = null): array
    {
        $date = $date ?? now()->subDay()->toDateString();
        $channels = $channels ?? $this->defaultChannels($date);

        $runs = [];

        foreach ($channels as $channel) {
            try {
                $runs[] = $this->reconcileChannel($channel, $date);
            } catch (Throwable $e) {
                $runs[] = $this->failRun($channel, $date, $e->getMessage());
            }
        }

        return [
            'date' => $date,
            'runs' => $runs,
            'total_runs' => count($runs),
            'total_diff' => (int) array_sum(array_column($runs, 'diff_count')),
        ];
    }

    /** 单渠道对账 */
    private function reconcileChannel(string $channel, string $date): array
    {
        // 1) 渠道侧交易流水：优先账单，降级日志
        $stmt = $this->billProvider->provideResult($channel, $date);
        $channelTxns = ($stmt->supported && $stmt->ok)
            ? $stmt->transactions
            : $this->logProvider->provide($channel, $date);

        // 2) 本地 success 支付单（该渠道当日）
        $localPayments = Payment::query()
            ->where('channel', $channel)
            ->where('status', Payment::STATUS_SUCCESS)
            ->whereDate('paid_at', $date)
            ->get();

        $run = PaymentReconciliationRun::query()->updateOrCreate(
            ['reconcile_date' => $date, 'channel' => $channel],
            [
                'status' => PaymentReconciliationRun::STATUS_RUNNING,
                'started_at' => now(),
                'local_count' => 0, 'channel_count' => 0, 'matched_count' => 0,
                'diff_count' => 0, 'local_amount' => '0', 'channel_amount' => '0',
            ],
        );

        $this->matchAndDiff($run, $channelTxns, $localPayments);

        // 3) 汇总
        $localTotal = '0';
        foreach ($localPayments as $p) {
            $localTotal = bcadd($localTotal, (string) $p->amount, 2);
        }
        $channelTotal = '0';
        foreach ($channelTxns as $t) {
            $channelTotal = bcadd($channelTotal, (string) ($t->amount ?? '0'), 2);
        }

        $run->local_count = $localPayments->count();
        $run->channel_count = count($channelTxns);
        // 匹配数 = 本地 success 笔数 − 渠道侧缺失（MISSING_CHANNEL）笔数；
        // MISSING_LOCAL 为渠道侧独有，不扣减本地匹配；重复/金额差异仍算「已匹配到本地」。
        $run->matched_count = $localPayments->count()
            - PaymentReconciliationDiff::query()
                ->where('run_id', $run->id)
                ->where('status', PaymentReconciliationDiff::STATUS_PENDING)
                ->where('diff_type', PaymentReconciliationDiff::TYPE_MISSING_CHANNEL)
                ->count();
        $run->local_amount = $localTotal;
        $run->channel_amount = $channelTotal;
        $run->diff_count = PaymentReconciliationDiff::query()
            ->where('run_id', $run->id)
            ->where('status', PaymentReconciliationDiff::STATUS_PENDING)
            ->count();
        $run->status = $run->diff_count > 0
            ? PaymentReconciliationRun::STATUS_PARTIAL
            : PaymentReconciliationRun::STATUS_DONE;
        $run->finished_at = now();
        $run->save();

        return [
            'channel' => $channel,
            'status' => $run->status,
            'local_count' => $run->local_count,
            'channel_count' => $run->channel_count,
            'matched_count' => $run->matched_count,
            'diff_count' => $run->diff_count,
        ];
    }

    /** 匹配 + 差异检测 + 幂等开单 */
    private function matchAndDiff(PaymentReconciliationRun $run, array $channelTxns, $localPayments): void
    {
        $localByTrade = [];
        $localByPaymentNo = [];
        foreach ($localPayments as $p) {
            if ($p->channel_trade_no) {
                $localByTrade[$p->channel_trade_no][] = $p;
            }
            $localByPaymentNo[$p->payment_no][] = $p;
        }

        $chanByTrade = [];
        foreach ($channelTxns as $t) {
            $chanByTrade[$t->tradeNo][] = $t;
        }

        $matchedLocalIds = [];

        // (1) 重复回调：同一渠道交易号多次成功回调
        foreach ($chanByTrade as $tradeNo => $group) {
            if (count($group) <= 1) {
                continue;
            }
            $first = $group[0];
            $local = $localByTrade[$tradeNo][0] ?? null;
            $this->upsertDiff($run, [
                'diff_type' => PaymentReconciliationDiff::TYPE_DUPLICATE_CALLBACK,
                'payment_no' => $local?->payment_no,
                'channel_trade_no' => $tradeNo,
                'order_no' => $local?->order_no,
            ], [
                'local_amount' => $local?->amount,
                'channel_amount' => $first->amount,
                'local_status' => $local?->status,
                'channel_status' => $first->status,
                'detail' => json_encode([
                    'callback_count' => count($group),
                    'trade_no' => $tradeNo,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            if ($local) {
                $matchedLocalIds[] = $local->id;
            }
        }

        // (2) 逐笔渠道交易匹配本地
        foreach ($channelTxns as $t) {
            if (count($chanByTrade[$t->tradeNo] ?? []) > 1) {
                continue; // 已作为重复回调处理
            }
            $local = $localByTrade[$t->tradeNo][0] ?? ($localByPaymentNo[$t->outTradeNo][0] ?? null);

            if (! $local) {
                $this->upsertDiff($run, [
                    'diff_type' => PaymentReconciliationDiff::TYPE_MISSING_LOCAL,
                    'payment_no' => null,
                    'channel_trade_no' => $t->tradeNo,
                    'order_no' => null,
                ], [
                    'local_amount' => null,
                    'channel_amount' => $t->amount,
                    'local_status' => null,
                    'channel_status' => $t->status,
                    'detail' => json_encode([
                        'trade_no' => $t->tradeNo,
                        'out_trade_no' => $t->outTradeNo,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
                continue;
            }

            $matchedLocalIds[] = $local->id;

            if (bccomp((string) $local->amount, (string) ($t->amount ?? '0'), 2) !== 0) {
                $this->upsertDiff($run, [
                    'diff_type' => PaymentReconciliationDiff::TYPE_AMOUNT_MISMATCH,
                    'payment_no' => $local->payment_no,
                    'channel_trade_no' => $t->tradeNo,
                    'order_no' => $local->order_no,
                ], [
                    'local_amount' => $local->amount,
                    'channel_amount' => $t->amount,
                    'local_status' => $local->status,
                    'channel_status' => $t->status,
                    'detail' => json_encode([
                        'local' => (string) $local->amount,
                        'channel' => (string) ($t->amount ?? '0'),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // (3) 本地 success 但渠道侧无对应交易（资金风险）
        foreach ($localPayments as $p) {
            if (in_array($p->id, $matchedLocalIds, true)) {
                continue;
            }
            $this->upsertDiff($run, [
                'diff_type' => PaymentReconciliationDiff::TYPE_MISSING_CHANNEL,
                'payment_no' => $p->payment_no,
                'channel_trade_no' => $p->channel_trade_no,
                'order_no' => $p->order_no,
            ], [
                'local_amount' => $p->amount,
                'channel_amount' => null,
                'local_status' => $p->status,
                'channel_status' => null,
                'detail' => json_encode(['note' => '本地 success 但渠道侧无对应交易记录'], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * 幂等开单：同一 (date, channel, payment_no|'', channel_trade_no|'', diff_type)
     * - 已有 pending → 仅刷新数值（每日重跑不重复开单）
     * - 已有非 pending（已处置）→ 不重开
     * - 都没有 → 新建 pending
     */
    private function upsertDiff(PaymentReconciliationRun $run, array $key, array $attrs): void
    {
        // reconcile_date 经 DateOnly cast读取为 Carbon，where 需规整为 Y-m-d 字符串才能命中
        $reconDate = $run->reconcile_date instanceof \Carbon\Carbon
            ? $run->reconcile_date->format('Y-m-d')
            : (string) $run->reconcile_date;

        $pending = PaymentReconciliationDiff::query()
            ->where('reconcile_date', $reconDate)
            ->where('channel', $run->channel)
            ->where('diff_type', $key['diff_type'])
            ->where('payment_no', $key['payment_no'])
            ->where('channel_trade_no', $key['channel_trade_no'])
            ->where('status', PaymentReconciliationDiff::STATUS_PENDING)
            ->first();

        if ($pending) {
            $pending->forceFill($attrs)->save();

            return;
        }

        $handled = PaymentReconciliationDiff::query()
            ->where('reconcile_date', $reconDate)
            ->where('channel', $run->channel)
            ->where('diff_type', $key['diff_type'])
            ->where('payment_no', $key['payment_no'])
            ->where('channel_trade_no', $key['channel_trade_no'])
            ->where('status', '!=', PaymentReconciliationDiff::STATUS_PENDING)
            ->exists();

        if ($handled) {
            return;
        }

        PaymentReconciliationDiff::create(array_merge([
            'run_id' => $run->id,
            'reconcile_date' => $run->reconcile_date,
            'channel' => $run->channel,
            'status' => PaymentReconciliationDiff::STATUS_PENDING,
        ], $key, $attrs));
    }

    private function failRun(string $channel, string $date, string $reason): array
    {
        $run = PaymentReconciliationRun::query()->updateOrCreate(
            ['reconcile_date' => $date, 'channel' => $channel],
            [
                'status' => PaymentReconciliationRun::STATUS_FAILED,
                'started_at' => now(),
                'finished_at' => now(),
                'note' => mb_substr($reason, 0, 500),
            ],
        );

        return [
            'channel' => $channel,
            'status' => $run->status,
            'local_count' => 0,
            'channel_count' => 0,
            'matched_count' => 0,
            'diff_count' => 0,
        ];
    }

    /** 自动决定的对账渠道集合（排除余额/线下这类无远程账单的渠道） */
    private function defaultChannels(string $date): array
    {
        $default = [Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY];
        $localChannels = Payment::query()
            ->where('status', Payment::STATUS_SUCCESS)
            ->whereDate('paid_at', $date)
            ->distinct()
            ->pluck('channel')
            ->all();

        $channels = array_values(array_unique(array_merge($default, $localChannels)));
        $channels = array_values(array_diff(
            $channels,
            [Payment::CHANNEL_BALANCE, Payment::CHANNEL_OFFLINE],
        ));

        return $channels;
    }

    // ---------------- 工单处置 ----------------

    /**
     * 处置差异（resolve=已处置 / ignore=已忽略），仅 pending 可处置，写操作日志。
     */
    public function resolve(int $diffId, int $operatorId, ?string $remark = null): PaymentReconciliationDiff
    {
        return $this->handle($diffId, $operatorId, PaymentReconciliationDiff::STATUS_RESOLVED, $remark);
    }

    public function ignore(int $diffId, int $operatorId, ?string $remark = null): PaymentReconciliationDiff
    {
        return $this->handle($diffId, $operatorId, PaymentReconciliationDiff::STATUS_IGNORED, $remark);
    }

    private function handle(int $diffId, int $operatorId, string $status, ?string $remark): PaymentReconciliationDiff
    {
        $diff = PaymentReconciliationDiff::find($diffId);
        if (! $diff) {
            throw BusinessException::notFound('对账差异记录不存在');
        }
        if (! $diff->isPending()) {
            throw BusinessException::conflict(sprintf(
                '该差异已处置（当前状态「%s」），不可重复处理',
                $diff->statusLabel(),
            ));
        }

        $diff->forceFill([
            'status' => $status,
            'handled_by' => $operatorId,
            'handled_at' => now(),
            'handle_remark' => $remark ?? ($status === PaymentReconciliationDiff::STATUS_IGNORED ? '人工忽略' : '已处置'),
        ])->save();

        $this->operationLog->record(
            $operatorId,
            'payment_reconcile',
            $status === PaymentReconciliationDiff::STATUS_IGNORED ? 'diff_ignored' : 'diff_resolved',
            'payment_reconciliation_diff',
            (int) $diff->id,
            [
                'channel' => $diff->channel,
                'diff_type' => $diff->diff_type,
                'payment_no' => $diff->payment_no,
                'channel_trade_no' => $diff->channel_trade_no,
                'remark' => $remark,
            ],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $diff->refresh();
    }

    /**
     * 全局对账统计（看板可视化）。
     *
     * @return array{
     *     total_runs:int, total_diffs:int,
     *     pending_diffs:int, processing_diffs:int, resolved_diffs:int, ignored_diffs:int,
     *     by_type:array<string,int>, trend:array<int,array{date:string,diffs:int}>
     * }
     */
    public function stats(): array
    {
        $totalRuns = PaymentReconciliationRun::query()->count();
        $totalDiffs = PaymentReconciliationDiff::query()->count();

        $byStatus = PaymentReconciliationDiff::query()
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $byType = PaymentReconciliationDiff::query()
            ->selectRaw('diff_type, count(*) as cnt')
            ->groupBy('diff_type')
            ->pluck('cnt', 'diff_type')
            ->all();

        // 近 14 天每日差异趋势（按 reconcile_date 补齐缺失日期）
        $since = now()->subDays(13)->startOfDay();
        $raw = PaymentReconciliationDiff::query()
            ->where('reconcile_date', '>=', $since->format('Y-m-d'))
            ->selectRaw('reconcile_date, count(*) as cnt')
            ->groupBy('reconcile_date')
            ->orderBy('reconcile_date')
            ->pluck('cnt', 'reconcile_date')
            ->all();

        $trend = [];
        for ($i = 0; $i < 14; $i++) {
            $d = $since->copy()->addDays($i)->format('Y-m-d');
            $trend[] = ['date' => $d, 'diffs' => (int) ($raw[$d] ?? 0)];
        }

        return [
            'total_runs' => (int) $totalRuns,
            'total_diffs' => (int) $totalDiffs,
            'pending_diffs' => (int) ($byStatus['pending'] ?? 0),
            'processing_diffs' => (int) ($byStatus['processing'] ?? 0),
            'resolved_diffs' => (int) ($byStatus['resolved'] ?? 0),
            'ignored_diffs' => (int) ($byStatus['ignored'] ?? 0),
            'by_type' => $byType,
            'trend' => $trend,
        ];
    }
}
