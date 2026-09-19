<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Admin\Concerns\WmsLogSummary;
use App\Http\Controllers\Controller;
use App\Models\ReturnInboundOrder;
use App\Services\Wms\ReturnInboundOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台退货入库单（WMS 计划 P4 / F9，Step 8）
 *
 * 权限统一 `wms.return.manage`；页面在 P6 落地，本阶段先把接口与审计做对。
 * `manual-received` 是回传丢失的兜底：走与 WMS 回传完全相同的
 * markReceived → complete 链路（计划 §4.3「等价于收货处理」）。
 *
 * 说明：主键用自增 int（后台内部资源，与发货单/仓库配置一致）。
 */
class WmsReturnInboundController extends Controller
{
    use ApiResponse;
    use WmsLogSummary;

    public function __construct(private readonly ReturnInboundOrderService $returns) {}

    /** GET /api/admin/wms/return-inbound-orders —— 退货入库单列表 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'inbound_no' => ['nullable', 'string', 'max:32'],
            'refund_no' => ['nullable', 'string', 'max:32'],
            'order_no' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ReturnInboundOrder::query()
            ->with('warehouse:id,code,name')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['warehouse_id'] ?? null, fn ($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($data['inbound_no'] ?? null, fn ($q, $no) => $q->where('inbound_no', 'like', "%{$no}%"))
            ->when($data['refund_no'] ?? null, fn ($q, $no) => $q->where('refund_no', 'like', "%{$no}%"))
            ->when($data['order_no'] ?? null, fn ($q, $no) => $q->where('order_no', 'like', "%{$no}%"))
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (ReturnInboundOrder $rio) => $this->row($rio));

        return $this->paginated($page);
    }

    /** GET /api/admin/wms/return-inbound-orders/{id} —— 详情（含行项目） */
    public function show(int $id): JsonResponse
    {
        $rio = ReturnInboundOrder::with(['items', 'warehouse:id,code,name'])->find($id);
        if (! $rio) {
            throw BusinessException::notFound('退货入库单不存在');
        }

        return $this->success($this->row($rio, withItems: true));
    }

    /** POST /api/admin/wms/return-inbound-orders/{id}/push —— 手工重推 */
    public function push(Request $request, int $id): JsonResponse
    {
        $rio = ReturnInboundOrder::find($id);
        if (! $rio) {
            throw BusinessException::notFound('退货入库单不存在');
        }

        $result = $this->returns->retryPush($rio, $request->user()->id);

        return $this->success(
            $this->row($result->load(['items', 'warehouse:id,code,name']), withItems: true),
            '已重新加入推送队列',
        );
    }

    /** POST /api/admin/wms/return-inbound-orders/{id}/cancel —— 取消（收货完成前） */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $rio = ReturnInboundOrder::find($id);
        if (! $rio) {
            throw BusinessException::notFound('退货入库单不存在');
        }

        $result = $this->returns->cancel($rio, $data['reason'], $request->user()->id);

        return $this->success(
            $this->row($result->load(['items', 'warehouse:id,code,name']), withItems: true),
            '退货入库单已取消',
        );
    }

    /**
     * POST /api/admin/wms/return-inbound-orders/{id}/manual-received —— 手工标记收货（回传丢失兜底）
     *
     * received_details 缺省按「全部应退行足额正品实收」处理；库存恢复、退款完成、
     * 事件通知与 WMS 回传共用同一条链路。
     */
    public function manualReceived(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'received_details' => ['nullable', 'array'],
            'received_details.*.sku_id' => ['nullable'],
            'received_details.*.platform_sku_code' => ['nullable', 'string', 'max:64'],
            'received_details.*.quantity' => ['required_with:received_details', 'integer', 'min:0'],
            'received_details.*.inventory_type' => ['nullable', 'in:ZP,CC'],
            'exception_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $rio = ReturnInboundOrder::find($id);
        if (! $rio) {
            throw BusinessException::notFound('退货入库单不存在');
        }

        $result = $this->returns->manualReceived(
            $rio,
            $data['received_details'] ?? null,
            $request->user()->id,
            $data['exception_reason'] ?? null,
        );

        return $this->success(
            $this->row($result->load(['items', 'warehouse:id,code,name']), withItems: true),
            '退货入库已完成',
        );
    }

    // ==================== 出口 ====================

    /** @return array<string, mixed> */
    private function row(ReturnInboundOrder $rio, bool $withItems = false): array
    {
        $row = [
            'id' => $rio->id,
            'refund_id' => $rio->refund_id,
            'refund_no' => $rio->refund_no,
            'order_id' => $rio->order_id,
            'order_no' => $rio->order_no,
            'inbound_no' => $rio->inbound_no,
            'warehouse_id' => $rio->warehouse_id,
            'warehouse_name' => $rio->warehouse?->name,
            'provider' => $rio->provider,
            'status' => $rio->status,
            'status_label' => $rio->statusLabel(),
            'wms_inbound_no' => $rio->wms_inbound_no,
            'push_request_id' => $rio->push_request_id,
            'push_times' => (int) $rio->push_times,
            'last_push_at' => $rio->last_push_at?->format('Y-m-d H:i:s'),
            'last_push_error' => $rio->last_push_error,
            'received_at' => $rio->received_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $rio->cancelled_at?->format('Y-m-d H:i:s'),
            'exception_reason' => $rio->exception_reason,
            'return_reason' => $rio->return_reason,
            'can_push' => in_array($rio->status, ReturnInboundOrder::PUSHABLE, true),
            'can_cancel' => in_array($rio->status, [
                ReturnInboundOrder::STATUS_CREATED,
                ReturnInboundOrder::STATUS_PENDING_PUSH,
                ReturnInboundOrder::STATUS_PUSH_FAILED,
                ReturnInboundOrder::STATUS_EXCEPTION,
                ReturnInboundOrder::STATUS_PUSHED,
                ReturnInboundOrder::STATUS_RECEIVING,
            ], true),
            'can_manual_received' => in_array($rio->status, [
                ReturnInboundOrder::STATUS_PUSHED,
                ReturnInboundOrder::STATUS_RECEIVING,
            ], true),
            'created_at' => $rio->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $rio->updated_at?->format('Y-m-d H:i:s'),
        ];

        if ($withItems) {
            // P6/F4：最近调用流水摘要
            $row['logs'] = $this->recentLogs($rio->inbound_no, $rio->push_request_id);
            $row['items'] = $rio->items->map(fn ($item) => [
                'id' => $item->id,
                'sku_id' => $item->sku_id,
                'platform_sku_code' => $item->platform_sku_code,
                'wms_sku_code' => $item->wms_sku_code,
                'product_name' => $item->product_name,
                'qty' => (int) $item->qty,
                'received_qty' => (int) $item->received_qty,
                'inventory_type' => $item->inventory_type,
                'barcode' => $item->barcode,
            ])->values()->all();
        }

        return $row;
    }
}
