<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 订单行出口（P2-11）：自身 id 与关联 product/sku 的 id 均改为对外 public_id（ULID）。
 */
class OrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'product_id' => $this->whenLoaded('product', fn () => $this->product?->public_id),
            'sku_id' => $this->whenLoaded('sku', fn () => $this->sku?->public_id),
            'product_title' => $this->product_title,
            'sku_specs' => $this->sku_specs ?? [],
            'sku_image' => $this->sku_image,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'total_amount' => $this->total_amount,
            'coupon_share' => $this->coupon_share,
            'promotion_share' => $this->promotion_share,
            'payable_amount' => number_format($this->payableAmount(), 2, '.', ''),
            'review' => $this->whenLoaded('review', function () {
                return $this->review ? [
                    'id' => $this->review->public_id,
                    'rating' => $this->review->rating,
                    'content' => $this->review->content,
                    'status' => $this->review->status,
                    'can_edit' => $this->review->canEdit(),
                ] : null;
            }),
        ];
    }
}
