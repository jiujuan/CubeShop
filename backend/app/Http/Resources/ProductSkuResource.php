<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SKU 出口（P2-11）：SKU 主键不外露（数量≈商品×规格，同样泄露商品规模），改 public_id。
 */
class ProductSkuResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'sku_code' => $this->sku_code,
            'specs' => $this->specs,
            'price' => $this->price,
            'stock' => $this->whenLoaded('inventory', fn () => $this->inventory?->stock ?? 0, 0),
            'status' => (int) $this->status,
        ];
    }
}
