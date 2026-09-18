<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 用户出口（P2-11）：对外只暴露 public_id，不暴露内部 int 主键。
 * 用于评价作者等跨用户引用场景。
 */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'avatar' => $this->avatar,
        ];
    }
}
