<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\Order\OrderLogService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 订单出口（P2-11）：列表与详情共用单一 Resource。
 * - id 改为对外 public_id（ULID）。
 * - items / refunds / logs 经 whenLoaded 区分场景（列表只带 items，详情再带 refunds/logs）。
 * - 关联 product/sku 的 id 同样走 public_id（见 OrderItemResource）。
 */
class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $items = $this->whenLoaded('items');

        $itemCount = is_iterable($items) ? (int) $items->sum('quantity') : null;

        $itemsPreview = is_iterable($items)
            ? $items->take(3)->map(fn ($i) => [
                'product_id' => $i->product?->public_id,
                'product_title' => $i->product_title,
                'sku_image' => $i->sku_image,
                'quantity' => $i->quantity,
            ])->values()->all()
            : [];

        return [
            'id' => $this->public_id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'status_label' => Order::STATUS_LABELS[$this->status] ?? $this->status,
            'total_amount' => $this->total_amount,
            'freight_amount' => $this->freight_amount,
            'pay_amount' => $this->pay_amount,
            // coupon_id 为营销配置内链（非用户可枚举实体），保留 int 主键
            'coupon_id' => $this->coupon_id,
            'discount_amount' => $this->discount_amount ?? '0.00',
            'promotion_discount' => $this->promotion_discount ?? '0.00',
            'amount_details' => $this->amount_details,
            'item_count' => $itemCount,
            'items_preview' => $itemsPreview,
            'items' => $this->whenLoaded('items', fn () => OrderItemResource::collection($this->items), []),
            'actions' => $this->actions(),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'remark' => $this->remark,
            'address_snapshot' => $this->address_snapshot,
            'cancel_reason' => $this->cancel_reason,
            'refunds' => $this->whenLoaded('refunds', function () {
                return $this->refunds->sortByDesc('id')->map(fn ($r) => [
                    'id' => $r->public_id,
                    'refund_no' => $r->refund_no,
                    'type' => $r->type,
                    'amount' => (string) $r->amount,
                    'reason' => $r->reason,
                    'images' => $r->images ?? [],
                    'status' => $r->status,
                    'return_status' => $r->return_status,
                    'channel' => $r->channel,
                    'refund_status' => $r->refund_status,
                    'failed_reason' => $r->failed_reason,
                    'retry_count' => (int) $r->retry_count,
                    'refunded_at' => $r->refunded_at?->format('Y-m-d H:i:s'),
                    'return_details' => $r->return_details,
                    'return_tracking_no' => $r->return_tracking_no,
                    'return_express_company' => $r->return_express_company,
                    'return_received_at' => $r->return_received_at?->format('Y-m-d H:i:s'),
                    'admin_remark' => $r->admin_remark,
                    'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
                ])->values()->all();
            }, []),
            'logs' => $this->whenLoaded('logs', fn () => app(OrderLogService::class)->timeline($this->resource)),
            'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $this->shipped_at?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d H:i:s'),
        ];
    }
}
