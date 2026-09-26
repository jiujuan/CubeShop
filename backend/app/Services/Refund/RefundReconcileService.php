<?php

namespace App\Services\Refund;

use App\Models\Payment;
use App\Models\PaymentReconciliationDiff;
use App\Models\PaymentReconciliationRun;
use App\Models\Refund;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use Throwable;

/**
 * 退款流水对账（Phase 5）
 *
 * 思路对齐支付对账（PaymentReconcileService），但退款是「逐单查渠道状态」而非「拉渠道账单」：
 * 微信/支付宝退款异步且渠道账单解析成本高，直接对每笔本地退款调网关 queryRefund 比对最稳妥，
 * 也能顺带发现「本地 processing 但渠道已成功」「本地 success 但渠道失败/关闭」等资损差异。
 *
 * 差异写入既有的 payment_reconciliation_diffs（与支付对账共用表，靠 diff_type 区分），
 * 并挂到当日同渠道的 reconciliation run（firstOrCreate 复用，绝不覆盖支付对账的 run 汇总）。
 * 部分唯一索引保证每日重跑不会把同一差异重复开单；已处置（resolved/ignored）不重开。
 */
class RefundReconcileService
{
    public function __construct(
        private readonly PaymentGatewayFactory $factory,
        private readonly PaymentChannelService $channels,
    ) {}

    /**
     * 执行退款对账。
     *
     * @param  string|null  $date      对账日 YYYY-MM-DD，默认昨天
     * @param  array|null   $channels  限定渠道（默认 wechat/alipay；余额/线下为同步退款，无远程状态可比对，跳过）
     * @return array{date:string, channels:array, diff_count:int}
     */
    public function run(?string $date = null, ?array $channels = null): array
    {
        $date = $date ?? now()->subDay()->toDateString();
        $channels = $channels ?? [Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY];

        $total = 0;
        foreach ($channels as $channel) {
            try {
                $total += $this->reconcileChannel($channel, $date);
            } catch (Throwable $e) {
                // 单渠道异常不中断整轮对账
                $total += 0;
            }
        }

        return [
            'date' => $date,
            'channels' => $channels,
            'diff_count' => $total,
        ];
    }

    private function reconcileChannel(string $channel, string $date): int
    {
        // 仅比对仍有「本地终态不确定」的退款：processing（待渠道确认）/ success（需核验渠道确实退了）
        $refunds = Refund::query()
            ->where('channel', $channel)
            ->whereIn('status', [Refund::STATUS_PROCESSING, Refund::STATUS_SUCCESS])
            ->whereDate('updated_at', $date)
            ->get();

        if ($refunds->isEmpty()) {
            return 0;
        }

        // 复用当日同渠道的 reconciliation run（支付对账可能已建；不存在则建一条 done 占位）
        $run = PaymentReconciliationRun::firstOrCreate(
            ['reconcile_date' => $date, 'channel' => $channel],
            [
                'status' => PaymentReconciliationRun::STATUS_DONE,
                'started_at' => now(),
                'finished_at' => now(),
            ],
        );

        $count = 0;
        foreach ($refunds as $refund) {
            if (empty($refund->out_refund_no)) {
                continue; // 无幂等单号无法反查渠道
            }

            $payment = Payment::query()
                ->where('order_id', $refund->order_id)
                ->where('biz_type', Payment::BIZ_TYPE_ORDER)
                ->where('status', Payment::STATUS_SUCCESS)
                ->first();
            if (! $payment) {
                continue;
            }

            try {
                $config = $this->channels->decryptedConfig($channel);
                $gateway = $this->factory->make($channel);
                $result = $gateway->queryRefund($payment, $refund->out_refund_no, $config);
            } catch (Throwable $e) {
                continue; // 查单异常（网络/鉴权/解析）跳过，等下次重跑
            }

            if (! $result->ok) {
                continue; // 网关不支持查单（余额/Mock）或查单失败，无法比对
            }

            $channelStatus = (string) $result->channelStatus;

            if ($refund->status === Refund::STATUS_PROCESSING && $channelStatus === 'SUCCESS') {
                // 渠道已退款成功，本地却还卡在 processing（回调/轮询漏掉）→ 资损风险，开单
                $count += $this->upsertDiff(
                    $run,
                    $refund,
                    PaymentReconciliationDiff::TYPE_REFUND_STATUS_MISMATCH,
                    '本地退款处理中，但渠道侧已退款成功（疑似回调/轮询漏处理）',
                    ['local_status' => $refund->status, 'channel_status' => $channelStatus],
                );
            } elseif ($refund->status === Refund::STATUS_SUCCESS && in_array($channelStatus, ['CLOSED', 'ABNORMAL', 'FAILED'], true)) {
                // 本地记成功，渠道却失败/关闭 → 状态错乱，开单
                $count += $this->upsertDiff(
                    $run,
                    $refund,
                    PaymentReconciliationDiff::TYPE_REFUND_STATUS_MISMATCH,
                    '本地退款成功，但渠道侧退款失败/关闭',
                    ['local_status' => $refund->status, 'channel_status' => $channelStatus],
                );
            } elseif ($refund->status === Refund::STATUS_PROCESSING && in_array($channelStatus, ['CLOSED', 'ABNORMAL'], true)) {
                // 渠道已关闭/异常，本地仍 processing → 应转 failed 却没转，开单
                $count += $this->upsertDiff(
                    $run,
                    $refund,
                    PaymentReconciliationDiff::TYPE_REFUND_CHANNEL_MISSING,
                    '渠道侧退款单已关闭/异常，本地仍处理中',
                    ['local_status' => $refund->status, 'channel_status' => $channelStatus],
                );
            }
            // processing↔processing / success↔success / 不支持状态：一致或无法判定，不开单
        }

        return $count;
    }

    /**
     * 幂等开单（与 PaymentReconcileService::upsertDiff 同键策略）：
     * 同一 (reconcile_date, channel, payment_no=refund_no, channel_trade_no=out_refund_no, diff_type)
     * - 已有 pending → 仅刷新渠道状态，不重复计数
     * - 已有非 pending（已处置）→ 不重开
     * - 都没有 → 新建 pending 并计数
     */
    private function upsertDiff(
        PaymentReconciliationRun $run,
        Refund $refund,
        string $diffType,
        string $note,
        array $extra,
    ): int {
        $reconDate = $run->reconcile_date instanceof \Carbon\Carbon
            ? $run->reconcile_date->format('Y-m-d')
            : (string) $run->reconcile_date;

        $pending = PaymentReconciliationDiff::query()
            ->where('reconcile_date', $reconDate)
            ->where('channel', $run->channel)
            ->where('diff_type', $diffType)
            ->where('payment_no', $refund->refund_no)
            ->where('channel_trade_no', $refund->out_refund_no)
            ->where('status', PaymentReconciliationDiff::STATUS_PENDING)
            ->first();

        if ($pending) {
            $pending->forceFill([
                'local_status' => $extra['local_status'] ?? null,
                'channel_status' => $extra['channel_status'] ?? null,
                'detail' => json_encode(
                    array_merge(['note' => $note, 'refund_no' => $refund->refund_no, 'out_refund_no' => $refund->out_refund_no], $extra),
                    JSON_UNESCAPED_UNICODE,
                ),
            ])->save();

            return 0;
        }

        $handled = PaymentReconciliationDiff::query()
            ->where('reconcile_date', $reconDate)
            ->where('channel', $run->channel)
            ->where('diff_type', $diffType)
            ->where('payment_no', $refund->refund_no)
            ->where('channel_trade_no', $refund->out_refund_no)
            ->where('status', '!=', PaymentReconciliationDiff::STATUS_PENDING)
            ->exists();

        if ($handled) {
            return 0;
        }

        PaymentReconciliationDiff::create([
            'run_id' => $run->id,
            'reconcile_date' => $run->reconcile_date,
            'channel' => $run->channel,
            'diff_type' => $diffType,
            'payment_no' => $refund->refund_no,
            'channel_trade_no' => $refund->out_refund_no,
            'order_no' => $refund->order_no,
            'local_status' => $extra['local_status'] ?? null,
            'channel_status' => $extra['channel_status'] ?? null,
            'detail' => json_encode(
                array_merge(['note' => $note, 'refund_no' => $refund->refund_no, 'out_refund_no' => $refund->out_refund_no], $extra),
                JSON_UNESCAPED_UNICODE,
            ),
            'status' => PaymentReconciliationDiff::STATUS_PENDING,
        ]);

        return 1;
    }
}
