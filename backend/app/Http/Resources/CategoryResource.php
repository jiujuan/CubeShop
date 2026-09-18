<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 分类出口（P2-11）：对外只暴露 public_id，不暴露 int 主键。
 */
class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
        ];
    }
}
