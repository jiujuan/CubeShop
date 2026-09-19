<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\FulfillmentOrder;
use App\Services\Wms\FulfillmentOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台发货单（WMS 计划 P1 / F8，Step 7）
 *
 * 权限拆分：查看 `wms.order.view`，治理（重推/取消）`wms.order.manage`。
 * 页面在 P6 落地，本阶段先把接口与审计做对。
 *
 * 说明：发货单主键用自增 int（后台内部资源，不对外暴露，与 P0 仓库/配置一致）。
 */
class WmsFulfillmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FulfillmentOrderService $fulfillments) {}

    /** GET /api/admin/wms/fulfillment-orders —— 发货单列表 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'outbound_no' => ['nullable', 'string', 'max:32'],
            'order_no' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = FulfillmentOrder::query()
            ->with('warehouse:id,code,name')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['warehouse_id'] ?? null, fn ($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($data['outbound_no'] ?? null, fn ($q, $no) => $q->where('outbound_no', 'like', "%{$no}%"))
            ->when($data['order_no'] ?? null, fn ($q, $no) => $q->where('order_no', 'like', "%{$no}%"))
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (FulfillmentOrder $fo) => $this->row($fo));

        return $this->paginated($page);
    }

    /** GET /api/admin/wms/fulfillment-orders/{id} —— 发货单详情（含行项目） */
    public function show(int $id): JsonResponse
    {
        $fo = FulfillmentOrder::with(['items', 'warehouse:id,code,name'])->find($id);
        if (! $fo) {
            throw BusinessException::notFound('发货单不存在');
        }

        return $this->success($this->row($fo, withItems: true));
    }

    /** POST /api/admin/wms/fulfillment-orders/{id}/push —— 手工重推（推送失败/异常修复后） */
    public function push(Request $request, int $id): JsonResponse
    {
        $fo = FulfillmentOrder::find($id);
        if (! $fo) {
            throw BusinessException::notFound('发货单不存在');
        }

        $result = $this->fulfillments->retryPush($fo, $request->user()->id);

        return $this->success(
            $this->row($result->load(['items', 'warehouse:id,code,name']), withItems: true),
            '已重新加入推送队列',
        );
    }

    /** POST /api/admin/wms/fulfillment-orders/{id}/cancel —— 取消发货单（出库前） */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $fo = FulfillmentOrder::find($id);
        if (! $fo) {
            throw BusinessException::notFound('发货单不存在');
        }

        $result = $this->fulfillments->cancel($fo, $data['reason'], $request->user()->id);

        return $this->success(
            $this->row($result->load(['items', 'warehouse:id,code,name']), withItems: true),
            '发货单已取消',
        );
    }

    // ==================== 出口 ====================

    /** @return array<string, mixed> */
    private function row(FulfillmentOrder $fo, bool $withItems = false): array
    {
        $row = [
            'id' => $fo->id,
            'order_id' => $fo->order_id,
            'order_no' => $fo->order_no,
            'outbound_no' => $fo->outbound_no,
            'warehouse_id' => $fo->warehouse_id,
            'warehouse_name' => $fo->warehouse?->name,
            'provider' => $fo->provider,
            'status' => $fo->status,
            'status_label' => $fo->statusLabel(),
            'wms_outbound_no' => $fo->wms_outbound_no,
            'tracking_no' => $fo->tracking_no,
            'carrier_code' => $fo->carrier_code,
            'carrier_name' => $fo->carrier_name,
            'push_request_id' => $fo->push_request_id,
            'push_times' => (int) $fo->push_times,
            'last_push_at' => $fo->last_push_at?->format('Y-m-d H:i:s'),
            'last_push_error' => $fo->last_push_error,
            'shipped_at' => $fo->shipped_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $fo->cancelled_at?->format('Y-m-d H:i:s'),
            'exception_reason' => $fo->exception_reason,
            'can_push' => in_array($fo->status, FulfillmentOrder::PUSHABLE, true),
            'can_cancel' => in_array($fo->status, FulfillmentOrder::cancellableStates(), true),
            'created_at' => $fo->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $fo->updated_at?->format('Y-m-d H:i:s'),
        ];

        if ($withItems) {
            $row['buyer_info'] = $fo->buyer_info;
            $row['shipping_info'] = $fo->shipping_info;
            $row['items'] = $fo->items->map(fn ($item) => [
                'id' => $item->id,
                'sku_id' => $item->sku_id,
                'platform_sku_code' => $item->platform_sku_code,
                'wms_sku_code' => $item->wms_sku_code,
                'product_name' => $item->product_name,
                'qty' => (int) $item->qty,
                'shipped_qty' => (int) $item->shipped_qty,
                'barcode' => $item->barcode,
            ])->values()->all();
        }

        return $row;
    }
}
