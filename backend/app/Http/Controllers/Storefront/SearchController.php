<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\Product\ProductSearchService;
use App\Support\ApiResponse;
use App\Support\PublicId;
use App\Support\Search\SearchCriteria;
use App\Support\Search\SearchPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 站内搜索（API：V1.2 站内搜索，无需登录）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §6
 *
 * 职责边界：**只做入参归一化与出参装配**，一行检索逻辑都不写 ——
 * 缓存、降级链、重排、词频全在 {@see ProductSearchService}。
 * 这样阶段二换引擎（Meilisearch / ES）时，本文件不需要改动。
 *
 * 对外标识一律 public_id（与 `/products` 一致）：入参 category_id / brand_id
 * 同时接受 public_id 与历史 int 主键，由 `PublicId::resolve()` 解析成内部 int 主键后
 * 才交给 `SearchCriteria`（DTO 里存的永远是内部主键）。
 */
final class SearchController extends Controller
{
    use ApiResponse;

    /** 联想条数上限（下拉框超过 20 条就没意义了，且每次输入都打一次接口） */
    public const MAX_SUGGEST = 20;

    /** 单页条数上限，与 `SearchCriteria::MAX_PAGE_SIZE` 对齐 */
    public const MAX_PAGE_SIZE = SearchCriteria::MAX_PAGE_SIZE;

    public function __construct(private readonly ProductSearchService $search)
    {
    }

    /**
     * 搜索主入口 GET /search
     *
     * 参数同 `/products`（keyword / category_id / brand_id / min_price / max_price /
     * attribute_values[] / sort / page / page_size），额外支持 `sort=relevance`
     * （有关键词时的默认排序；无关键词时回落到 `newest`）。
     */
    public function index(Request $request): JsonResponse
    {
        $criteria = self::criteriaFrom($request, false);
        $page = $this->search->search($criteria, $request->ip());

        return $this->success([
            'list' => $page->items->map(fn ($product) => new ProductResource($product))->all(),
            'pagination' => $page->pagination($this->shouldExposeTotal()),
            'meta' => $this->meta($criteria, $page),
        ]);
    }

    /**
     * 联想候选 GET /search/suggest
     *
     * @return JsonResponse data 为 `string[]`
     */
    public function suggest(Request $request): JsonResponse
    {
        $limit = self::clamp((int) $request->query('limit', 10), 1, self::MAX_SUGGEST);

        return $this->success(
            $this->search->suggest((string) $request->query('keyword', ''), $limit)
        );
    }

    /**
     * 热搜榜 GET /search/hot
     *
     * @return JsonResponse data 为 `string[]`
     */
    public function hot(Request $request): JsonResponse
    {
        $limit = self::clamp((int) $request->query('limit', 10), 1, 50);

        return $this->success($this->search->hotKeywords($limit));
    }

    /**
     * 从请求归一化检索条件
     *
     * ⚠️ 对外标识在这里完成 public_id → int 的转换，DTO 内部只有主键。
     *
     * @param  bool  $keepLegacySort  true = 不传 sort 时回落 `newest`（`/products` 兼容用）；
     *                                false = 交给 `SearchCriteria` 按有无关键词决定（`/search` 用）
     */
    public static function criteriaFrom(Request $request, bool $keepLegacySort): SearchCriteria
    {
        $params = $request->query();

        if (! is_array($params)) {
            $params = [];
        }

        $params['category_id'] = PublicId::resolve(PublicId::SCOPE_CATEGORY, $request->query('category_id'));
        $params['brand_id'] = PublicId::resolve(PublicId::SCOPE_BRAND, $request->query('brand_id'));

        // 兼容旧行为：`/products` 未显式传 sort 时一直是「sort 倒序 + id 倒序」，
        // 不能因为改走服务层就悄悄变成相关度排序
        if ($keepLegacySort && ! $request->has('sort')) {
            $params['sort'] = 'newest';
        }

        return SearchCriteria::fromArray($params);
    }

    /**
     * 搜索元信息
     *
     * - `related_categories` 仅在结果非空时给（命中结果的分类聚合，供前端二次筛选）；
     * - `recommendations` 仅在结果为空时给（不白屏）；
     * - `relaxed` / `engine` 是诊断信息，受 `search.expose_debug` 控制，默认不暴露。
     *
     * @return array<string, mixed>
     */
    private function meta(SearchCriteria $criteria, SearchPage $page): array
    {
        $meta = ['keyword' => $criteria->keyword];

        if ($page->isEmpty()) {
            $meta['recommendations'] = $page->recommendations
                ->map(fn ($product) => new ProductResource($product))
                ->all();
        } else {
            $meta['related_categories'] = $page->relatedCategories;
        }

        if ($this->search->shouldExposeDebug()) {
            $meta['relaxed'] = $page->relaxed;
            $meta['engine'] = $page->engine;
        }

        return $meta;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        if ($value <= 0) {
            return $min;
        }

        return min($value, $max);
    }
}
