<?php

namespace App\Http\Resources;

use App\Services\Favorite\FavoriteService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 商品出口（P2-11）：对外标识统一为 public_id（ULID）；分类/品牌节点同样去 int 主键。
 *
 * 列表场景仅预载 category；详情场景预载 skus.inventory / images / brand / attributeValues。
 * is_favorited 依赖当前登录用户，在 Resource 内即时计算。
 */
class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        $skus = $this->whenLoaded('skus');

        $minPrice = is_iterable($skus) ? $skus->where('status', 1)->min('price') : null;
        $totalStock = is_iterable($skus)
            ? (int) $skus->filter(fn ($s) => (int) $s->status === 1)->sum(fn ($s) => $s->inventory?->stock ?? 0)
            : 0;

        $isFavorited = false;
        if ($request->user()) {
            $isFavorited = app(FavoriteService::class)->isFavorited($request->user()->id, $this->id);
        }

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'main_image' => $this->main_image,
            'images' => $this->whenLoaded('images', fn () => $this->images->sortBy('sort')->pluck('url')->values(), []),
            'description' => $this->description,
            'price' => (string) ($minPrice ?? $this->price),
            'sales_count' => $this->sales_count,
            'status' => (int) $this->status,
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($this->category)),
            'is_favorited' => $isFavorited,
            'brand' => $this->whenLoaded('brand', fn () => new BrandResource($this->brand)),
            'brand_id' => $this->brand_id,
            'video_url' => $this->video_url,
            'weight' => (int) $this->weight,
            'attributes' => $this->whenLoaded('attributeValues', function () {
                return $this->attributeValues
                    ->filter(fn ($v) => $v->attribute !== null)
                    ->map(fn ($v) => [
                        'attribute_id' => $v->attribute_id,
                        'name' => $v->attribute->name,
                        'type' => $v->attribute->type,
                        'value' => $v->value,
                    ])->values();
            }, []),
            'total_stock' => $totalStock,
            'skus' => $this->whenLoaded('skus', fn () => ProductSkuResource::collection($this->skus), []),
        ];
    }
}
