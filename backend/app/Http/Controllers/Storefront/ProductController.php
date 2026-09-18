<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PublicId;
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
        // P2-11：分类对外只暴露 public_id；入参接受 public_id 或历史 int 主键
        if ($rawCategoryId = $request->query('category_id')) {
            $categoryId = PublicId::resolve(PublicId::SCOPE_CATEGORY, $rawCategoryId);
            if ($categoryId !== null) {
                $categoryIds = [$categoryId];
                foreach (Category::where('parent_id', $categoryId)->pluck('id') as $childId) {
                    $categoryIds[] = $childId;
                }
                $q->whereIn('category_id', $categoryIds);
            }
        }
        if ($minPrice = $request->query('min_price')) {
            $q->where('price', '>=', (float) $minPrice);
        }
        if ($maxPrice = $request->query('max_price')) {
            $q->where('price', '<=', (float) $maxPrice);
        }

        // V1.1 E01 / T-014：品牌筛选（P2-11：brand_id 接受 public_id 或历史 int 主键）
        if ($rawBrandId = $request->query('brand_id')) {
            $brandId = PublicId::resolve(PublicId::SCOPE_BRAND, $rawBrandId);
            if ($brandId !== null) {
                $q->where('brand_id', $brandId);
            }
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

        // P2-11：统一走 ProductResource（id 改为 public_id，关联分类/品牌同样去 int 主键）
        $paginator->getCollection()->transform(fn ($p) => new ProductResource($p));

        return $this->paginated($paginator);
    }

    /**
     * 商品详情（含 SKU 库存） GET /products/{id}
     *
     * P2-11 终态：入参同时接受 public_id 与历史 int 主键，出参一律只给 public_id。
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $productId = PublicId::resolve(PublicId::SCOPE_PRODUCT, $id);

        $product = $productId === null ? null : Product::query()
            ->where('status', 1)
            ->with(['skus.inventory', 'images', 'category:id,name', 'brand:id,name', 'attributeValues.attribute:id,name,type'])
            ->find($productId);

        if (! $product) {
            throw BusinessException::notFound('商品不存在或已下架');
        }

        return $this->success(new ProductResource($product));
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
                // P2-11：分类对外只暴露 public_id
                'id' => $root->public_id,
                'name' => $root->name,
                'children' => $categories->where('parent_id', $root->id)->values()
                    ->map(fn ($c) => ['id' => $c->public_id, 'name' => $c->name])->all(),
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
            'hot' => $hot->map(fn ($p) => new ProductResource($p))->all(),
            'newest' => $newest->map(fn ($p) => new ProductResource($p))->all(),
        ]);
    }
}
