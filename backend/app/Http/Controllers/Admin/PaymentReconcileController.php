<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\PaymentReconciliationDiff;
use App\Models\Payment;
use App\Models\PaymentReconciliationRun;
use App\Services\Common\OperationLogService;
use App\Services\Payment\PaymentReconcileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 支付渠道日终对账（A7-支付渠道对账，权限 payment.reconcile.view / payment.reconcile.handle）
 *
 * - 运行清单：/admin/payment-reconciles（每渠道每日一条批次头）
 * - 差异清单：/admin/payment-reconcile-diffs（差异兼工单，pending 可处置）
 * - 处置：/admin/payment-reconcile-diffs/{id}/resolve
 * - 导出：/admin/payment-reconcile-diffs/export（CSV，随差异清单同套筛选条件）
 * - 看板：/admin/payment-reconciles/stats（支持按来源端 platform 过滤）
 */
class PaymentReconcileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentReconcileService $reconcile,
        private readonly OperationLogService $opLog,
    ) {}

    /** 对账运行清单（批次头） */
    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'channel' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:running,done,partial,failed'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PaymentReconciliationRun::query()
            ->when($data['date'] ?? null, fn ($q, $v) => $q->where('reconcile_date', $v))
            ->when($data['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $paginator = $query->orderByDesc('reconcile_date')
            ->orderBy('channel')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (PaymentReconciliationRun $run) => [
            'id' => $run->id,
            'reconcile_date' => $run->reconcile_date?->format('Y-m-d'),
            'channel' => $run->channel,
            'channel_label' => Payment::CHANNEL_LABELS[$run->channel] ?? $run->channel,
            'status' => $run->status,
            'status_label' => $run->statusLabel(),
            'local_count' => $run->local_count,
            'channel_count' => $run->channel_count,
            'matched_count' => $run->matched_count,
            'diff_count' => $run->diff_count,
            'local_amount' => (string) $run->local_amount,
            'channel_amount' => (string) $run->channel_amount,
            'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'created_at' => $run->created_at?->format('Y-m-d H:i:s'),
        ]);

        return $this->success([
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /** 单个运行详情（含差异汇总） */
    public function showRun(int $id): JsonResponse
    {
        $run = PaymentReconciliationRun::find($id);
        if (! $run) {
            throw BusinessException::notFound('对账运行记录不存在');
        }

        $byType = PaymentReconciliationDiff::query()
            ->where('run_id', $run->id)
            ->where('status', PaymentReconciliationDiff::STATUS_PENDING)
            ->selectRaw('diff_type, count(*) as cnt')
            ->groupBy('diff_type')
            ->pluck('cnt', 'diff_type')
            ->all();

        return $this->success([
            'id' => $run->id,
            'reconcile_date' => $run->reconcile_date?->format('Y-m-d'),
            'channel' => $run->channel,
            'status' => $run->status,
            'status_label' => $run->statusLabel(),
            'local_count' => $run->local_count,
            'channel_count' => $run->channel_count,
            'matched_count' => $run->matched_count,
            'diff_count' => $run->diff_count,
            'local_amount' => (string) $run->local_amount,
            'channel_amount' => (string) $run->channel_amount,
            'note' => $run->note,
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'pending_by_type' => $byType,
        ]);
    }

    /** 差异清单（兼工单） */
    public function diffs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'channel' => ['nullable', 'string', 'max:32'],
            'diff_type' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:pending,processing,resolved,ignored'],
            'platform' => ['nullable', 'string', 'in:web,h5,miniprogram'],
            'keyword' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->diffQuery($data)
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (PaymentReconciliationDiff $diff) => $this->formatDiff($diff));

        return $this->success([
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 导出对账差异报告（CSV，Excel 兼容 UTF-8 BOM）。
     * 筛选条件与差异清单一致，不分页，随当前筛选导出全量。
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'channel' => ['nullable', 'string', 'max:32'],
            'diff_type' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:pending,processing,resolved,ignored'],
            'platform' => ['nullable', 'string', 'in:web,h5,miniprogram'],
            'keyword' => ['nullable', 'string', 'max:64'],
        ]);

        $query = $this->diffQuery($data)->orderByDesc('id');

        $filename = 'payment-reconcile-diffs-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($query) {
            // UTF-8 BOM：Excel 直接打开不乱码
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['对账日期', '渠道', '平台', '差异类型', '支付单号', '渠道交易号', '订单号',
                '本地金额', '渠道金额', '本地状态', '渠道状态', '状态', '处置时间', '处置备注']);

            $query->cursor()->each(function (PaymentReconciliationDiff $diff) use ($out) {
                fputcsv($out, [
                    $diff->reconcile_date?->format('Y-m-d'),
                    Payment::CHANNEL_LABELS[$diff->channel] ?? $diff->channel,
                    $diff->platform === null ? '' : (Payment::PLATFORM_LABELS[$diff->platform] ?? $diff->platform),
                    $diff->typeLabel(),
                    $diff->payment_no ?? '',
                    $diff->channel_trade_no ?? '',
                    $diff->order_no ?? '',
                    $diff->local_amount === null ? '' : (string) $diff->local_amount,
                    $diff->channel_amount === null ? '' : (string) $diff->channel_amount,
                    $diff->local_status ?? '',
                    $diff->channel_status ?? '',
                    $diff->statusLabel(),
                    $diff->handled_at?->format('Y-m-d H:i:s'),
                    $diff->handle_remark,
                ]);
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 处置差异工单（权限 payment.reconcile.handle）
     * body: { action: resolve|ignore, remark? }
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:resolve,ignore'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $before = PaymentReconciliationDiff::find($id);
        if (! $before) {
            throw BusinessException::notFound('对账差异记录不存在');
        }
        $beforeStatus = $before->status;

        $diff = $data['action'] === 'ignore'
            ? $this->reconcile->ignore($id, $request->user()->id, $data['remark'] ?? null)
            : $this->reconcile->resolve($id, $request->user()->id, $data['remark'] ?? null);

        $this->opLog->record($request->user()->id, 'payment_reconcile', 'admin_'.$data['action'], 'payment_reconciliation_diff', $id, [
            'channel' => $diff->channel,
            'diff_type' => $diff->diff_type,
            'payment_no' => $diff->payment_no,
            'before' => $beforeStatus,
            'after' => $diff->status,
            'remark' => $data['remark'] ?? null,
        ]);

        return $this->success([
            'id' => $diff->id,
            'status' => $diff->status,
            'status_label' => $diff->statusLabel(),
        ], $data['action'] === 'ignore' ? '已忽略' : '已处置');
    }

    /** 看板统计（批次/差异总数、按状态、按类型、按渠道拆分、近14天趋势；支持按来源端过滤） */
    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:web,h5,miniprogram'],
        ]);

        return $this->success($this->reconcile->stats($data['platform'] ?? null));
    }

    /** 差异清单/导出共用的筛选查询 */
    private function diffQuery(array $data)
    {
        return PaymentReconciliationDiff::query()
            ->when($data['date'] ?? null, fn ($q, $v) => $q->where('reconcile_date', $v))
            ->when($data['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($data['diff_type'] ?? null, fn ($q, $v) => $q->where('diff_type', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($data['keyword'] ?? null, function ($q, $v) {
                $like = '%'.$v.'%';
                $q->where(function ($q) use ($like) {
                    $q->where('payment_no', 'like', $like)
                        ->orWhere('channel_trade_no', 'like', $like)
                        ->orWhere('order_no', 'like', $like);
                });
            });
    }

    /** 差异行输出结构 */
    private function formatDiff(PaymentReconciliationDiff $diff): array
    {
        return [
            'id' => $diff->id,
            'reconcile_date' => $diff->reconcile_date?->format('Y-m-d'),
            'channel' => $diff->channel,
            'channel_label' => Payment::CHANNEL_LABELS[$diff->channel] ?? $diff->channel,
            'platform' => $diff->platform,
            'platform_label' => $diff->platform === null ? null : (Payment::PLATFORM_LABELS[$diff->platform] ?? $diff->platform),
            'diff_type' => $diff->diff_type,
            'diff_type_label' => $diff->typeLabel(),
            'payment_no' => $diff->payment_no,
            'channel_trade_no' => $diff->channel_trade_no,
            'order_no' => $diff->order_no,
            'local_amount' => $diff->local_amount === null ? null : (string) $diff->local_amount,
            'channel_amount' => $diff->channel_amount === null ? null : (string) $diff->channel_amount,
            'local_status' => $diff->local_status,
            'channel_status' => $diff->channel_status,
            'detail' => $diff->detail,
            'status' => $diff->status,
            'status_label' => $diff->statusLabel(),
            'handled_by' => $diff->handled_by,
            'handled_at' => $diff->handled_at?->format('Y-m-d H:i:s'),
            'handle_remark' => $diff->handle_remark,
            'created_at' => $diff->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
