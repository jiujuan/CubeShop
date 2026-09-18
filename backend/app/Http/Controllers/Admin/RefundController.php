<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Refund\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台退款处理（API 文档 8.4 / Roadmap P5）
 */
class RefundController extends Controller
{
    use ApiResponse;

    public function __construct(private RefundService $refunds)
    {
    }

    /** 退款列表：GET /admin/refunds */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refund_no' => ['nullable', 'string'],
            'order_no' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [Refund::STATUS_PENDING, Refund::STATUS_APPROVED, Refund::STATUS_REJECTED, Refund::STATUS_SUCCESS, Refund::STATUS_FAILED])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Refund::query()
            ->when($data['refund_no'] ?? null, fn ($q, $v) => $q->where('refund_no', $v))
            ->when($data['order_no'] ?? null, fn ($q, $v) => $q->where('order_no', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Refund $refund) => $this->row($refund));

        return $this->paginated($paginator);
    }

    /** 审核退款：POST /admin/refunds/{id}/process {action, admin_remark} */
    public function process(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:approve,reject'],
            'admin_remark' => ['nullable', 'string', 'max:200'],
        ]);

        $refund = Refund::find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $refund = $this->refunds->process(
            refund: $refund,
            adminId: $request->user()->id,
            action: $data['action'],
            adminRemark: $data['admin_remark'] ?? null,
        );

        return $this->success($this->row($refund->fresh()), '处理成功');
    }

    /**
     * 确认收货（退货退款专用）：POST /admin/refunds/{id}/receive {received_details, exception_reason}
     * 等效菜鸟回传收货；按实收正品回库存 → 退款完成。
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'received_details' => ['required', 'array', 'min:1'],
            'received_details.*.sku_id' => ['required'],
            'received_details.*.quantity' => ['required', 'integer', 'min:0'],
            'received_details.*.condition' => ['required', 'string', 'in:good,defective'],
            'exception_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $refund = Refund::find($id);
        if (! $refund) {
            throw BusinessException::notFound('退款单不存在');
        }

        $refund = $this->refunds->receiveReturn(
            refund: $refund,
            adminId: $request->user()->id,
            receivedDetails: $data['received_details'],
            exceptionReason: $data['exception_reason'] ?? null,
        );

        return $this->success($this->row($refund->fresh()), '已确认收货，退款已完成');
    }

    private function row(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_no' => $refund->refund_no,
            'order_id' => $refund->order_id,
            'order_no' => $refund->order_no,
            'user_id' => $refund->user_id,
            'type' => $refund->type,
            'amount' => (string) $refund->amount,
            'reason' => $refund->reason,
            'status' => $refund->status,
            'return_status' => $refund->return_status,
            'return_tracking_no' => $refund->return_tracking_no,
            'return_express_company' => $refund->return_express_company,
            'return_details' => $refund->return_details,
            'return_received_details' => $refund->return_received_details,
            'return_received_at' => $refund->return_received_at?->format('Y-m-d H:i:s'),
            'return_exception_reason' => $refund->return_exception_reason,
            'admin_remark' => $refund->admin_remark,
            'processed_at' => $refund->processed_at?->format('Y-m-d H:i:s'),
            'created_at' => $refund->created_at?->format('Y-m-d H:i:s'),
            'order_status' => Order::whereKey($refund->order_id)->value('status'),
        ];
    }
}
