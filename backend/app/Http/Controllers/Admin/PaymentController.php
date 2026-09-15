<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Common\OperationLogService;
use App\Services\Payment\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台支付管理（payments / payment_logs，API 文档 8.11）
 *
 * - 查看：payment.view（运营 + 超管）
 * - 关闭待支付单：payment.manage（仅超管），仅 pending 可关，关闭不联动取消订单
 */
class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly OperationLogService $opLog,
    ) {}

    /**
     * 支付单列表（含汇总统计）
     * GET /admin/payments?payment_no=&order_no=&user_id=&channel=&status=&start_time=&end_time=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_no' => ['nullable', 'string', 'max:64'],
            'order_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'in:'.implode(',', array_keys(Payment::CHANNEL_LABELS))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Payment::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->buildQuery($data);

        $paginator = $query
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Payment $payment) => $this->row($payment));

        return $this->success([
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
            'summary' => $this->summary($data),
        ]);
    }

    /**
     * 支付单详情（含支付日志时间轴与关联订单摘要）
     * GET /admin/payments/{id}
     */
    public function show(int $id): JsonResponse
    {
        $payment = Payment::query()->with('logs')->find($id);

        if (! $payment) {
            throw BusinessException::notFound('支付单不存在');
        }

        $order = Order::query()->find($payment->order_id);

        return $this->success($this->row($payment) + [
            'order' => $order ? [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'status' => $order->status,
                'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
                'pay_amount' => (string) $order->pay_amount,
                'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            ] : null,
            'logs' => $payment->logs
                ->sortBy('id')
                ->values()
                ->map(fn (PaymentLog $log) => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'event_label' => $log->event_label,
                    'request_data' => $log->request_data,
                    'response_data' => $log->response_data,
                    'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                ])
                ->all(),
        ]);
    }

    /**
     * 关闭支付单（仅待支付）
     * POST /admin/payments/{id}/close  body: { reason? }
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $payment = Payment::query()->find($id);
        if (! $payment) {
            throw BusinessException::notFound('支付单不存在');
        }

        $before = $payment->status;
        $payment = $this->payments->close($payment, $request->user()->id, $data['reason'] ?? null);

        $this->opLog->record($request->user()->id, 'payment', 'close', 'payment', $payment->id, [
            'payment_no' => $payment->payment_no,
            'order_no' => $payment->order_no,
            'before' => $before,
            'after' => $payment->status,
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->success($this->row($payment), '支付单已关闭');
    }

    /**
     * 支付单导出（CSV，UTF-8 BOM，最多 5000 条）
     * GET /admin/payments/export（同列表筛选条件）
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'payment_no' => ['nullable', 'string', 'max:64'],
            'order_no' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'in:'.implode(',', array_keys(Payment::CHANNEL_LABELS))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(Payment::STATUS_LABELS))],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
        ]);

        $payments = $this->buildQuery($data)->orderByDesc('id')->limit(5000)->get();
        $filename = 'payments-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($payments) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');

            fputcsv($out, ['支付单号', '订单号', '用户ID', '渠道', '金额', '状态', '渠道交易号', '支付时间', '创建时间']);

            foreach ($payments as $payment) {
                fputcsv($out, [
                    $payment->payment_no,
                    $payment->order_no,
                    $payment->user_id,
                    $payment->channel_label,
                    (string) $payment->amount,
                    $payment->status_label,
                    (string) $payment->channel_trade_no,
                    $payment->paid_at?->format('Y-m-d H:i:s'),
                    $payment->created_at?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** 列表 / 导出共用筛选 */
    private function buildQuery(array $data)
    {
        return Payment::query()
            ->when($data['payment_no'] ?? null, fn ($q, $v) => $q->where('payment_no', 'like', '%'.$v.'%'))
            ->when($data['order_no'] ?? null, fn ($q, $v) => $q->where('order_no', 'like', '%'.$v.'%'))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($data['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['start_time'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($data['end_time'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));
    }

    /** 当前筛选条件下的汇总（不受分页影响） */
    private function summary(array $data): array
    {
        $rows = $this->buildQuery($data)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'success' then 1 else 0 end) as success_count")
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as success_amount")
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_count")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed_count")
            ->first();

        return [
            'total' => (int) $rows->total,
            'success_count' => (int) $rows->success_count,
            'success_amount' => number_format((float) $rows->success_amount, 2, '.', ''),
            'pending_count' => (int) $rows->pending_count,
            'failed_count' => (int) $rows->failed_count,
        ];
    }

    /** 列表项 / 详情共用结构 */
    private function row(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_no' => $payment->payment_no,
            'order_id' => $payment->order_id,
            'order_no' => $payment->order_no,
            'user_id' => $payment->user_id,
            'channel' => $payment->channel,
            'channel_label' => $payment->channel_label,
            'amount' => (string) $payment->amount,
            'status' => $payment->status,
            'status_label' => $payment->status_label,
            'channel_trade_no' => $payment->channel_trade_no,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
            'log_count' => $payment->relationLoaded('logs') ? $payment->logs->count() : (int) $payment->logs()->count(),
        ];
    }
}
