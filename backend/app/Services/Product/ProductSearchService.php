<?php

namespace App\Services\Product;

use App\Jobs\Search\UpdateSearchKeyword;
use App\Models\Category;
use App\Models\Product;
use App\Support\Search\ProductSearchEngine;
use App\Support\Search\SearchConfig;
use App\Support\Search\SearchCriteria;
use App\Support\Search\SearchEngineResolver;
use App\Support\Search\SearchPage;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchTokenizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 商品检索服务（站内搜索 S1-06）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4 / §4.6 / §4.7 / §6
 *
 * 引擎之上、控制器之下的一层，把四件事收在一处：
 *
 * 1. **缓存** —— 只缓存引擎命中集（id + 总数），不缓存商品行（价格/库存要读最新值）；
 * 2. **降级链编排** —— 第 0 步（单字中文 → LIKE 引擎）、第 4 步（仍零结果 → 推荐位）；
 * 3. **重排** —— 筛选与排序全在 Eloquent（§5.1 约束 2），相关度排序在 PHP 侧按引擎顺序还原；
 * 4. **词频投递** —— 有结果的搜索异步记一笔，节流后入队。
 *
 * ⚠️ 分页总数必须在**筛选之后**重算：引擎给的 `SearchResult::total` 未经分类/品牌/价格/属性
 * 筛选，拿它分页会出现「total=86 但翻到第 3 页就空了」。
 *
 * ⚠️ 本类只依赖 `ProductSearchEngine` 接口与 `SearchEngineResolver`，
 * **不 import 任何引擎具体类**（§5.1 约束 1）—— 阶段二换引擎时这里一行都不用改。
 */
final class ProductSearchService
{
    /** 缓存 TTL 默认值（秒），`search.cache_ttl` 可覆盖 */
    public const CACHE_TTL = 60;

    /** 热词缓存更久：词频变化慢，且热搜榜本身就该稳 */
    public const HOT_CACHE_TTL = 300;

    /** 命中数达到该值视为热词，命中集缓存延长到 300s */
    public const HOT_HIT_THRESHOLD = 50;

    /** 同一 IP + 同一词多久内只计一次词频（秒） */
    public const KEYWORD_THROTTLE = 60;

    /** 空结果时「同分类热销」条数 */
    public const RECOMMEND_SAME_CATEGORY = 8;

    /** 空结果时「首页推荐」条数 */
    public const RECOMMEND_HOME = 4;

    /** 同义词展开最多跑几组替换查询（规则失控时的熔断，防一次搜索放大成一串全表扫描） */
    public const SYNONYM_MAX_QUERIES = 5;

    public function __construct(
        private readonly SearchEngineResolver $engines,
        private readonly SearchTokenizer $tokenizer,
        private readonly SearchConfig $config,
    ) {
    }

    /**
     * 搜索（有关键词走引擎，无关键词退化为普通浏览）
     *
     * @param  ?string  $ip  用于词频节流；不传则按「无 IP」维度节流
     */
    public function search(SearchCriteria $criteria, ?string $ip = null): SearchPage
    {
        return $criteria->hasKeyword()
            ? $this->searchByKeyword($criteria, $ip)
            : $this->browse($criteria);
    }

    /**
     * 联想候选（委托当前引擎，控制器不直接碰引擎）
     *
     * @return list<string>
     */
    public function suggest(string $keyword, int $limit = 10): array
    {
        return $this->engines->primary()->suggest($keyword, $limit);
    }

    /**
     * 热搜榜
     *
     * 缓存 300s：词频是累加的，抖动一次不影响排序，没必要每次都查库。
     *
     * @return list<string>
     */
    public function hotKeywords(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        return Cache::remember(
            sprintf('search:hot:%d', $limit),
            self::HOT_CACHE_TTL,
            fn (): array => \App\Models\SearchKeyword::query()
                ->active()
                ->orderByDesc('hit_count')
                ->orderBy('keyword')
                ->limit($limit)
                ->pluck('keyword')
                ->all(),
        );
    }

    // ---------------------------------------------------------------- 有关键词

    private function searchByKeyword(SearchCriteria $criteria, ?string $ip): SearchPage
    {
        $engine = $this->pickEngine($criteria->keyword);
        $result = $this->cachedEngineResult($engine, $criteria->keyword);

        if ($result->ids === []) {
            // 降级链第 3 步：同义词展开重查（S1-10 补做，设计 §4.8）
            $expanded = $this->synonymResult($engine, $criteria->keyword);

            if ($expanded === null) {
                // 降级链第 4 步：仍零结果 → 不白屏，给推荐位
                return SearchPage::empty($criteria, $result->engine, $this->recommendations($criteria));
            }

            $result = $expanded;
        }

        $query = $this->filteredQuery($criteria, $result->ids);
        $this->applySort($query, $criteria);

        // 引擎命中上限 MAX_HITS，所以 pluck 一次拿到「筛选后的全部命中」是安全的；
        // 顺带解决了 total 必须在筛选后重算的问题，不必再跑一遍 count
        // ⚠️ pluck('id') 而不是 pluck('products.id')：结果集里列名是 `id`（`products.*` 展开后），
        // 写成表名前缀会取到 null
        $matchedIds = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($criteria->sortByRelevance()) {
            $matchedIds = $this->rankByEngine($matchedIds, $result->ids);
        }

        $total = count($matchedIds);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageIds = array_slice($matchedIds, $offset, $criteria->pageSize);

        $page = new SearchPage(
            items: $this->fetchInOrder($pageIds),
            total: $total,
            page: $criteria->page,
            pageSize: $criteria->pageSize,
            relaxed: $result->relaxed,
            engine: $result->engine,
            relatedCategories: $this->relatedCategories($matchedIds),
        );

        $this->recordKeyword($criteria->keyword, $total, $ip);

        return $page;
    }

    /**
     * 降级链第 0 步：单字中文直接走 LIKE 引擎
     *
     * bigram 对单字无意义（「椅」参与的每个 bigram 都命中，召回全是噪声），
     * 实测 PG 引擎对单字查询零命中，所以这里必须绕开它。
     */
    private function pickEngine(string $keyword): ProductSearchEngine
    {
        return $this->tokenizer->isSingleCjkChar($keyword)
            ? $this->engines->like()
            : $this->engines->primary();
    }

    /**
     * 降级链第 3 步：同义词展开重查（S1-10 补做，设计 §4.4 / §4.8）
     *
     * 原查询零结果后才走到这里。规则匹配是**子串级**的：归一化 + 小写后
     * `mb_strpos` 命中 `from_word` 即替换 —— 「北欧手机壳」里的「手机壳」也会被展开。
     * 每条候选 `to_word` 各自作为一次完整查询走引擎（引擎内部自己 AND→OR），
     * 多组命中在 Service 合并（并集、同 id 取最高分）。
     *
     * ⚠️ 只有真的展开出候选且查到东西才返回结果；无规则可用 / 展开查询仍零命中
     * 返回 null，外层继续走推荐位 —— 不要用「空的 SearchResult」区分这两种情况，
     * 否则推荐位判断会被迫看 relaxed 标志，语义变脆。
     */
    private function synonymResult(ProductSearchEngine $engine, string $keyword): ?SearchResult
    {
        if (! $this->synonymsEnabled()) {
            return null;
        }

        $normalized = mb_strtolower($this->tokenizer->normalize($keyword));

        if ($normalized === '') {
            return null;
        }

        $queries = [];

        foreach (\App\Models\SearchSynonym::activeMap() as $from => $toWords) {
            if ($from === '' || mb_strpos($normalized, $from) === false) {
                continue;
            }

            foreach ($toWords as $to) {
                if ($to !== '') {
                    // 小写化后做 str_replace：归一化入库约定保证了词面一致
                    $queries[] = str_replace($from, $to, $normalized);
                }
            }
        }

        $queries = array_values(array_unique(array_slice($queries, 0, self::SYNONYM_MAX_QUERIES)));

        if ($queries === []) {
            return null;
        }

        $ids = [];
        $scores = [];

        foreach ($queries as $query) {
            $candidate = $this->cachedEngineResult($engine, $query);

            foreach ($candidate->ids as $id) {
                if (! isset($scores[$id])) {
                    $ids[] = $id;
                }

                $scores[$id] = max($scores[$id] ?? 0.0, $candidate->scores[$id] ?? 0.0);
            }
        }

        if ($ids === []) {
            return null;
        }

        return new SearchResult(
            ids: $ids,
            scores: $scores,
            total: count($ids),
            relaxed: true,
            engine: $engine->name(),
        );
    }

    /** 同义词展开是否启用（`search.synonyms_enabled`，库 → env → 默认开） */
    private function synonymsEnabled(): bool
    {
        return ! in_array(strtolower((string) $this->configValue('search.synonyms_enabled')), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * 命中集缓存
     *
     * key 只取「引擎名 + 关键词」，**不含分页与筛选**：
     * - 筛选不下推到引擎（约束 2），换筛选条件引擎结果不变；
     * - 引擎一次最多给 MAX_HITS 条，翻页只是换切片。
     * 这样「翻到第 5 页」不会重新算一遍相关度，缓存才有意义。
     *
     * `index_version` 用于批量失效：切引擎或全量重建后 +1，旧缓存自然过期
     * （database/array 缓存没有 tag，这是唯一低成本的做法）。
     *
     * ⚠️ **缓存数组而不是 `SearchResult` 对象**：Laravel 12 的缓存 store 反序列化时带
     * `allowed_classes` 白名单（默认不允许任何类），缓存对象取回来一律是
     * `__PHP_Incomplete_Class` —— 且 array 缓存 store 不会暴露这个问题，只有 file/database/redis 会。
     */
    private function cachedEngineResult(ProductSearchEngine $engine, string $keyword): SearchResult
    {
        $key = sprintf('search:v%d:%s:%s', $this->indexVersion(), $engine->name(), md5($keyword));

        return SearchResult::fromArray(Cache::remember(
            $key,
            $this->ttlFor($keyword),
            fn (): array => $engine->search(SearchCriteria::fromArray(['keyword' => $keyword]))->toArray(),
        ));
    }

    // ---------------------------------------------------------------- 无关键词

    private function browse(SearchCriteria $criteria): SearchPage
    {
        $query = $this->filteredQuery($criteria);
        $this->applySort($query, $criteria);

        $paginator = $query->paginate($criteria->pageSize, ['*'], 'page', $criteria->page);

        return new SearchPage(
            items: $paginator->getCollection(),
            total: $paginator->total(),
            page: $criteria->page,
            pageSize: $criteria->pageSize,
            relaxed: false,
            engine: 'none',
        );
    }

    // ---------------------------------------------------------------- 查询构造

    /**
     * @param  list<int>|null  $ids  引擎命中集；不传表示不过滤 id（无关键词浏览）
     * @return Builder<Product>
     */
    private function filteredQuery(SearchCriteria $criteria, ?array $ids = null): Builder
    {
        $query = Product::query()
            ->where('status', 1)
            ->with('category:id,public_id,name')
            ->addSelect([
                'products.*',
                'total_stock' => \App\Models\ProductSku::query()
                    ->selectRaw('coalesce(sum(i.stock),0)')
                    ->join('inventories as i', 'i.sku_id', '=', 'product_skus.id')
                    ->whereColumn('product_skus.product_id', 'products.id')
                    ->limit(1),
            ]);

        if ($ids !== null) {
            // 空集写进 whereIn 会生成 `0 = 1`，语义正确但看着像 bug，显式表达
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        if ($criteria->categoryId !== null) {
            $categoryIds = [$criteria->categoryId];

            foreach (Category::where('parent_id', $criteria->categoryId)->pluck('id') as $childId) {
                $categoryIds[] = (int) $childId;
            }

            $query->whereIn('category_id', $categoryIds);
        }

        if ($criteria->brandId !== null) {
            $query->where('brand_id', $criteria->brandId);
        }

        if ($criteria->minPrice !== null) {
            $query->where('price', '>=', $criteria->minPrice);
        }

        if ($criteria->maxPrice !== null) {
            $query->where('price', '<=', $criteria->maxPrice);
        }

        // 同属性多值 OR、跨属性 AND（与现有 /products 逐字同语义）
        foreach ($criteria->attributeValues as $attributeId => $values) {
            $query->whereHas('attributeValues', function (Builder $sub) use ($attributeId, $values): void {
                $sub->where('attribute_id', $attributeId)->whereIn('value', array_unique($values));
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, SearchCriteria $criteria): void
    {
        match ($criteria->sort) {
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'sales_desc' => $query->orderByDesc('sales_count')->orderByDesc('id'),
            // 相关度：DB 侧只给稳定兜底序，真正的顺序在 PHP 侧按引擎 rank 还原
            'relevance' => $query->orderByDesc('id'),
            // newest / 未知值：与现有 /products 默认序保持一致
            default => $query->orderByDesc('sort')->orderByDesc('id'),
        };
    }

    /**
     * 按引擎给出的相关度顺序重排
     *
     * @param  list<int>  $matched  筛选后的命中
     * @param  list<int>  $ranked  引擎给出的顺序
     * @return list<int>
     */
    private function rankByEngine(array $matched, array $ranked): array
    {
        $position = [];

        foreach ($ranked as $index => $id) {
            $position[$id] = $index;
        }

        $filtered = array_values(array_filter($matched, static fn (int $id): bool => isset($position[$id])));

        usort($filtered, static fn (int $a, int $b): int => $position[$a] <=> $position[$b]);

        return $filtered;
    }

    /**
     * @param  list<int>  $ids  期望顺序
     * @return Collection<int, Product>
     */
    private function fetchInOrder(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->with('category:id,public_id,name')
            ->addSelect([
                'products.*',
                'total_stock' => \App\Models\ProductSku::query()
                    ->selectRaw('coalesce(sum(i.stock),0)')
                    ->join('inventories as i', 'i.sku_id', '=', 'product_skus.id')
                    ->whereColumn('product_skus.product_id', 'products.id')
                    ->limit(1),
            ])
            ->get();

        // whereIn 不保证顺序，按请求的顺序还原（相关度序就是这么来的）
        return $products->sortBy(fn (Product $product): int => array_search($product->getKey(), $ids, true) ?: 0)->values();
    }

    /**
     * 命中结果的分类聚合（供前端二次筛选）
     *
     * @param  list<int>  $ids
     * @return list<array{id: string, name: string, count: int}>
     */
    private function relatedCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = DB::table('products')
            ->whereIn('id', $ids)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->get();

        if ($counts->isEmpty()) {
            return [];
        }

        $names = Category::query()
            ->whereIn('id', $counts->pluck('category_id')->all())
            ->get(['id', 'name', 'public_id'])
            ->keyBy('id');

        $out = [];

        foreach ($counts as $row) {
            $category = $names->get((int) $row->category_id);

            if ($category === null) {
                continue;
            }

            $out[] = [
                'id' => (string) $category->public_id,
                'name' => (string) $category->name,
                'count' => (int) $row->total,
            ];
        }

        return $out;
    }

    /**
     * 空结果的推荐位：同分类热销 + 首页推荐
     *
     * @return Collection<int, Product>
     */
    private function recommendations(SearchCriteria $criteria): Collection
    {
        $out = new Collection;
        $taken = [];

        $sameCategory = Product::query()
            ->where('status', 1)
            ->when($criteria->categoryId !== null, fn (Builder $q) => $q->where('category_id', $criteria->categoryId))
            ->orderByDesc('sales_count')
            ->limit(self::RECOMMEND_SAME_CATEGORY)
            ->get();

        foreach ($sameCategory as $product) {
            $out->push($product);
            $taken[] = $product->getKey();
        }

        $home = Product::query()
            ->where('status', 1)
            ->where('is_home_recommended', true)
            ->whereNotIn('id', $taken === [] ? [0] : $taken)
            ->orderByDesc('sort')
            ->orderByDesc('created_at')
            ->limit(self::RECOMMEND_HOME)
            ->get();

        foreach ($home as $product) {
            $out->push($product);
        }

        return $out;
    }

    // ---------------------------------------------------------------- 词频

    /**
     * 词频投递：只记「有结果」的搜索，同 IP + 同词 60s 内只计一次
     *
     * `Cache::add` 是「不存在才写」，天然原子，比 get+put 少一次竞态窗口。
     *
     * ⚠️ 不用 `DB::afterCommit`：测试跑在事务里，afterCommit 永不被执行，
     * 会表现为「词频功能在本地永远不生效」这种极难定位的问题。
     */
    private function recordKeyword(string $keyword, int $resultCount, ?string $ip): void
    {
        if ($resultCount <= 0) {
            return;
        }

        $normalized = $this->tokenizer->normalize($keyword);

        if ($normalized === '') {
            return;
        }

        $key = sprintf('search:kwthrottle:%s', md5($normalized.'|'.($ip ?? '')));

        if (! Cache::add($key, 1, self::KEYWORD_THROTTLE)) {
            return;
        }

        UpdateSearchKeyword::dispatch($normalized, $resultCount);
    }

    // ---------------------------------------------------------------- 配置

    private function indexVersion(): int
    {
        return $this->config->indexVersion();
    }

    private function ttlFor(string $keyword): int
    {
        $ttl = (int) ($this->configValue('search.cache_ttl') ?: self::CACHE_TTL);

        if ($ttl <= 0) {
            return self::CACHE_TTL;
        }

        // 热词命中集更稳定，缓存久一点（判断用的热词表本身也有 300s 缓存，不查库）
        return $this->isHotKeyword($keyword) ? max($ttl, self::HOT_CACHE_TTL) : $ttl;
    }

    private function isHotKeyword(string $keyword): bool
    {
        $normalized = $this->tokenizer->normalize($keyword);

        $hits = Cache::remember('search:hotmap', self::HOT_CACHE_TTL, fn (): array => \App\Models\SearchKeyword::query()
            ->active()
            ->orderByDesc('hit_count')
            ->limit(200)
            ->pluck('hit_count', 'keyword')
            ->all());

        return (int) ($hits[$normalized] ?? 0) >= self::HOT_HIT_THRESHOLD;
    }

    /**
     * 是否在接口里暴露诊断字段（`relaxed` / `engine`）
     *
     * 默认关闭：暴露内部引擎名等于告诉调用方「现在是降级链路」，生产上没必要让外部知道。
     * 排障时把 `search.expose_debug` 置 1 即可（后台配置或 `system_configs` 直接写）。
     */
    public function shouldExposeDebug(): bool
    {
        return in_array(strtolower((string) $this->configValue('search.expose_debug')), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 读 `search.*` 配置
     *
     * 走 {@see SearchConfig}（库 → env → 默认），且读库失败时由它静默回落：
     * 后台改的开关要立刻生效，同时配置没就绪也不能让搜索 500。
     */
    private function configValue(string $key): mixed
    {
        return $this->config->get($key);
    }
}
