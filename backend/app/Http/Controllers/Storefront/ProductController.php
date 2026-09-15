<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Exceptions\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台商品模块（API 文档 4，无需登录）
 */
class ProductController extends Controller
{
    use ApiResponse;

    /** 商品列表 / 搜索 GET /products */
    public function index(Request $request): JsonResponse
    {
        $q = Product::query()
            ->where('status', 1)
            ->with('category:id,name')
            ->addSelect([
                'products.*',
                'total_stock' => \App\Models\ProductSku::query()
                    ->selectRaw('coalesce(sum(i.stock),0)')
                    ->join('inventories as i', 'i.sku_id', '=', 'product_skus.id')
                    ->whereColumn('product_skus.product_id', 'products.id')
                    ->limit(1),
            ]);

        if ($keyword = trim((string) $request->query('keyword'))) {
            $q->where(function ($query) use ($keyword) {
                $query->where('title', 'like', "%{$keyword}%")
                    ->orWhere('subtitle', 'like', "%{$keyword}%");
            });
        }
        if ($categoryId = (int) $request->query('category_id')) {
            $categoryIds = [$categoryId];
            foreach (Category::where('parent_id', $categoryId)->pluck('id') as $childId) {
                $categoryIds[] = $childId;
            }
            $q->whereIn('category_id', $categoryIds);
        }
        if ($minPrice = $request->query('min_price')) {
            $q->where('price', '>=', (float) $minPrice);
        }
        if ($maxPrice = $request->query('max_price')) {
            $q->where('price', '<=', (float) $maxPrice);
        }

        // V1.1 E01 / T-014：品牌筛选
        if ($brandId = (int) $request->query('brand_id')) {
            $q->where('brand_id', $brandId);
        }

        // V1.1 E01 / T-014：属性筛选
        // 语义：同一属性内多值为 OR，跨属性为 AND
        // 参数格式：attribute_values[]=<attribute_id>:<value>
        $pairs = (array) $request->query('attribute_values', []);
        $byAttribute = [];
        foreach ($pairs as $pair) {
            if (! is_string($pair) || ! str_contains($pair, ':')) {
                continue;
            }
            [$attributeId, $value] = explode(':', $pair, 2);
            $attributeId = (int) $attributeId;
            if ($attributeId <= 0 || $value === '') {
                continue;
            }
            $byAttribute[$attributeId][] = $value;
        }
        foreach ($byAttribute as $attributeId => $values) {
            $q->whereHas('attributeValues', function ($sub) use ($attributeId, $values) {
                $sub->where('attribute_id', $attributeId)->whereIn('value', array_unique($values));
            });
        }

        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'price_asc' => $q->orderBy('price'),
            'price_desc' => $q->orderByDesc('price'),
            'sales_desc' => $q->orderByDesc('sales_count'),
            default => $q->orderByDesc('sort')->orderByDesc('id'),
        };

        $paginator = $q->paginate((int) $request->query('page_size', 20));

        $paginator->getCollection()->transform(fn ($p) => $this->brief($p));

        return $this->paginated($paginator);
    }

    /** 商品详情（含 SKU 库存） GET /products/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $product = Product::query()
            ->where('status', 1)
            ->with(['skus.inventory', 'images', 'category:id,name', 'brand:id,name', 'attributeValues.attribute:id,name,type'])
            ->find($id);

        if (! $product) {
            throw BusinessException::notFound('商品不存在或已下架');
        }

        $minPrice = $product->skus->where('status', 1)->min('price');

        return $this->success([
            'id' => $product->id,
            'title' => $product->title,
            'subtitle' => $product->subtitle,
            'main_image' => $product->main_image,
            'images' => $product->images->sortBy('sort')->pluck('url')->values(),
            'description' => $product->description,
            'price' => (string) ($minPrice ?? $product->price),
            'sales_count' => $product->sales_count,
            'status' => (int) $product->status,
            'category' => $product->category?->only(['id', 'name']),
            // V1.1 F05 / T-024：登录用户是否已收藏
            'is_favorited' => $request->user()
                ? app(\App\Services\Favorite\FavoriteService::class)->isFavorited($request->user()->id, $product->id)
                : false,
            // V1.1 E01：品牌 / 视频 / 重量 / 商品参数
            'brand' => $product->brand?->only(['id', 'name']),
            'brand_id' => $product->brand_id,
            'video_url' => $product->video_url,
            'weight' => (int) $product->weight,
            'attributes' => $product->attributeValues
                ->filter(fn ($v) => $v->attribute !== null)
                ->map(fn ($v) => [
                    'attribute_id' => $v->attribute_id,
                    'name' => $v->attribute->name,
                    'type' => $v->attribute->type,
                    'value' => $v->value,
                ])->values(),
            'total_stock' => (int) $product->skus
                ->filter(fn ($s) => $s->status == 1)
                ->sum(fn ($s) => $s->inventory?->stock ?? 0),
            'skus' => $product->skus
                ->filter(fn ($s) => $s->status == 1)
                ->map(fn ($sku) => [
                    'id' => $sku->id,
                    'sku_code' => $sku->sku_code,
                    'specs' => $sku->specs,
                    'price' => $sku->price,
                    'stock' => $sku->inventory?->stock ?? 0,
                    'status' => (int) $sku->status,
                ])->values(),
        ]);
    }

    /** 分类树 GET /products/categories（仅启用） */
    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->where('status', 1)
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get();

        return $this->success(
            $categories->where('parent_id', 0)->values()->map(fn ($root) => [
                'id' => $root->id,
                'name' => $root->name,
                'children' => $categories->where('parent_id', $root->id)->values()
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            ])->all()
        );
    }

    /** 热销 / 推荐 GET /products/hot */
    public function hot(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 10), 50);

        $hot = Product::query()
            ->where('status', 1)
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();

        // 新品：最新上架
        $newest = Product::query()
            ->where('status', 1)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->success([
            'hot' => $hot->map(fn ($p) => $this->brief($p))->all(),
            'newest' => $newest->map(fn ($p) => $this->brief($p))->all(),
        ]);
    }

    /** 列表简要字段 */
    private function brief(Product $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'subtitle' => $p->subtitle,
            'main_image' => $p->main_image,
            'price' => $p->price,
            'sales_count' => $p->sales_count,
            'total_stock' => (int) ($p->total_stock ?? 0),
            'category' => $p->category?->only(['id', 'name']),
        ];
    }
}
