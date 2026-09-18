<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 评价出口（P2-11）：对外 id 改为 public_id（ULID）。
 * - 公开场景（商品评价列表）：隐藏 user_id / order_id / order_item_id，仅暴露脱敏后的 nickname/avatar。
 * - 本人场景（我的评价）：由控制器置 for_self=true，补充商品引用（public_id）与 can_edit。
 * - 跨用户引用（作者）仅暴露对外 public_id，绝不暴露内部 user_id。
 */
class ReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        $base = [
            'id' => $this->public_id,
            'rating' => $this->rating,
            'content' => $this->content,
            'images' => $this->images ?? [],
            'is_anonymous' => (bool) $this->is_anonymous,
            'status' => $this->status,
            'status_label' => Review::STATUS_LABELS[$this->status] ?? $this->status,
            'reply_content' => $this->reply_content,
            'reply_at' => $this->reply_at?->format('Y-m-d H:i:s'),
            'edited_at' => $this->edited_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'nickname' => $this->displayName(),
            'avatar' => $this->is_anonymous ? null : $this->user?->avatar,
            // 跨用户引用（评价作者）：仅暴露对外 public_id，不暴露内部 user_id
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
        ];

        // 本人资源场景：补充内部关联与商品引用（商品 id 用 public_id）
        if ($this->for_self ?? false) {
            $base['product'] = $this->whenLoaded('product', fn () => [
                'id' => $this->product->public_id,
                'title' => $this->product->title,
                'main_image' => $this->product->main_image,
            ]);
            $base['product_id'] = $this->product_id;
            $base['order_id'] = $this->order_id;
            $base['order_item_id'] = $this->order_item_id;
            $base['can_edit'] = $this->canEdit();
        }

        return $base;
    }
}
