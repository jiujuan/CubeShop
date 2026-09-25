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

/**
 * 支付渠道日终对账（A7-支付渠道对账，权限 payment.reconcile.view / payment.reconcile.handle）
 *
 * - 运行清单：/admin/payment-reconciles（每渠道每日一条批次头）
 * - 差异清单：/admin/payment-reconcile-diffs（差异兼工单，pending 可处置）
 * - 处置：/admin/payment-reconcile-diffs/{id}/resolve
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
            'keyword' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PaymentReconciliationDiff::query()
            ->when($data['date'] ?? null, fn ($q, $v) => $q->where('reconcile_date', $v))
            ->when($data['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($data['diff_type'] ?? null, fn ($q, $v) => $q->where('diff_type', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['keyword'] ?? null, function ($q, $v) {
                $like = '%'.$v.'%';
                $q->where(function ($q) use ($like) {
                    $q->where('payment_no', 'like', $like)
                        ->orWhere('channel_trade_no', 'like', $like)
                        ->orWhere('order_no', 'like', $like);
                });
            });

        $paginator = $query->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (PaymentReconciliationDiff $diff) => [
            'id' => $diff->id,
            'reconcile_date' => $diff->reconcile_date?->format('Y-m-d'),
            'channel' => $diff->channel,
            'channel_label' => Payment::CHANNEL_LABELS[$diff->channel] ?? $diff->channel,
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

    /** 看板统计（批次/差异总数、按状态、按类型、近14天趋势） */
    public function stats(): JsonResponse
    {
        return $this->success($this->reconcile->stats());
    }
}
