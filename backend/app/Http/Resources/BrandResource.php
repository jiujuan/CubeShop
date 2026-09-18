<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 品牌出口（P2-11）：对外只暴露 public_id。
 */
class BrandResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
        ];
    }
}
