<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Product\ProductSearchService;
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

    /**
     * 商品列表 / 搜索 GET /products
     *
     * V1.2 站内搜索：内部改走 `ProductSearchService`（与 `GET /search` 同一条链路），
     * **对外行为保持不变**——参数、排序、分页结构、SEC-04 的 total 管控全部逐字一致，
     * 只把关键词匹配从「整串 LIKE」换成「引擎检索 + 相关度排序」。
     *
     * ⚠️ 兼容要点：未显式传 `sort` 时回落 `newest`（旧实现是 `sort desc, id desc`），
     * 不能因为换了链路就悄悄变成相关度排序 —— 相关度是 `GET /search` 的默认行为。
     */
    public function index(Request $request, ProductSearchService $search): JsonResponse
    {
        $page = $search->search(SearchController::criteriaFrom($request, true), $request->ip());

        return $this->success([
            'list' => $page->items->map(fn ($product) => new ProductResource($product))->all(),
            'pagination' => $page->pagination($this->shouldExposeTotal()),
        ]);
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
            ->with(['skus.inventory', 'images', 'category:id,public_id,name', 'brand:id,public_id,name', 'attributeValues.attribute:id,name,type'])
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

    /**
     * 首页推荐 GET /products/recommended（P-HomeRecommend）
     *
     * 取后台勾选「首页推荐」且处于上架状态的商品：
     * - 排序：sort 倒序 → 上架时间（created_at）倒序 → id 倒序兜底；
     * - 下架商品即使勾了也不露出，避免运营下架后首页仍展示。
     */
    public function recommended(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 12), 1), 50);

        $list = Product::query()
            ->where('status', 1)
            ->where('is_home_recommended', true)
            ->with('category:id,public_id,name')
            ->orderByDesc('sort')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->success([
            'list' => $list->map(fn ($p) => new ProductResource($p))->all(),
        ]);
    }
}
