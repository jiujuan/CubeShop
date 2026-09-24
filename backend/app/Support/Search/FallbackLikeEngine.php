<?php

namespace App\Support\Search;

use App\Models\Product;

/**
 * LIKE 降级引擎（站内搜索 S1-05）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4 降级链
 *
 * 三种情况下由它兜底：**SQLite 测试环境**、**PG 未就绪**（迁移没跑/驱动不是 pgsql）、
 * **第三方引擎宕机**（阶段二）。对应降级链第 0 步：单字中文查询（如「椅」）也直接走它
 * —— bigram 对单字无意义，参不参与都一样，不如省掉一轮 tsvector 计算。
 *
 * 三条刻意的边界：
 * 1. **只查 `title` / `subtitle`**，与改造前 `/products?keyword=` 的 LIKE 逐字同语义
 *    （唯一差异：两侧包 `lower()`，把 SQLite LIKE 的 ASCII 大小写不敏感显式带给 PG
 *    —— 跨驱动行为一致，英文关键词不会在 PG 上悄悄少召回）。
 *    不查 `keywords` / 品牌 / 分类名 —— 那些是 PG 索引域的增量，加进来会静默改变既有
 *    接口的行为（召回变超集），而降级引擎的职责是「不白屏、不劣化」，不是「追平 PG 召回」。
 *    真要追平，等 Service 层把 `/search` 换成 PG 引擎即可，降级路径不必对齐。
 * 2. **`isAvailable()` 恒为 true**：它是最后一道闸，自己再不可用就等于搜索整体不可用。
 * 3. **AND → OR 放宽**与 PG 引擎同构（`relaxed` 标记一致），切换引擎时前端提示逻辑不变。
 *
 * ⚠️ LIKE 是子串匹配，无相关度概念。这里的 score 是**自定义的覆盖率分**：
 * 标题命中数 × 1.0 + 仅副标题命中数 × 0.4，再按 token 总数归一 —— 保证「标题全命中」永远
 * 排在「只在副标题沾上一个词」之前，且不同查询的分数量纲一致（都在 0~1）。
 */
final class FallbackLikeEngine implements ProductSearchEngine
{
    public const NAME = 'like';

    /** 标题命中权重 */
    private const WEIGHT_TITLE = 1.0;

    /** 仅副标题命中的权重（低于标题，副标题是补充信息） */
    private const WEIGHT_SUBTITLE = 0.4;

    private const MAX_HITS = SearchCriteria::MAX_HITS;

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly SearchIndexWriter $writer,
        private readonly SearchSuggester $suggester,
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        $keyword = $this->tokenizer->normalize($criteria->keyword);

        if ($keyword === '') {
            return SearchResult::empty(self::NAME);
        }

        $terms = $this->terms($keyword);

        if ($terms === []) {
            return SearchResult::empty(self::NAME);
        }

        // 与 PG 引擎同构：先 AND（精确率优先），零结果才放宽为 OR
        $exact = $this->run($terms, 'and');

        if (! $exact->isEmpty()) {
            return $exact;
        }

        if (count($terms) > 1) {
            $relaxed = $this->run($terms, 'or');

            if (! $relaxed->isEmpty()) {
                return $relaxed;
            }
        }

        return SearchResult::empty(self::NAME, relaxed: count($terms) > 1);
    }

    /**
     * @return list<string>
     */
    public function suggest(string $keyword, int $limit): array
    {
        return $this->suggester->suggest($keyword, $limit);
    }

    public function reindex(int $productId): void
    {
        $this->writer->reindex($productId);
    }

    public function reindexAll(int $chunk = 500, ?\Closure $progress = null): int
    {
        return $this->writer->reindexQuery(Product::query()->withTrashed(), $chunk, $progress);
    }

    public function remove(int $productId): void
    {
        $this->writer->clear($productId);
    }

    /**
     * 查询词 → 匹配词
     *
     * 分词后为空（纯英文停用词 / 纯符号，如 `the and`、`！！！`）时**退回整串**：
     * 现状 LIKE 就是拿整串去比的，若这里返回空，`/products?keyword=the` 会从「有结果」
     * 变成「零结果」—— 那不是降级，是劣化。
     *
     * @return list<string>
     */
    private function terms(string $keyword): array
    {
        $tokens = $this->tokenizer->tokens($keyword);

        return $tokens === [] ? [$keyword] : $tokens;
    }

    /**
     * @param  list<string>  $terms
     */
    private function run(array $terms, string $mode): SearchResult
    {
        $base = $this->buildQuery($terms, $mode);

        $rows = (clone $base)
            ->limit(self::MAX_HITS)
            ->get(['id', 'title', 'subtitle', 'sales_count']);

        if ($rows->isEmpty()) {
            return SearchResult::empty(self::NAME, relaxed: $mode === 'or');
        }

        // 命中数小于上限时取回的行数就是总数；触顶才需要额外 count（LIKE 已是全表扫描，
        // 多一次 count 只发生在「命中超 1000 条」这种本就该走 PG 引擎的场景）
        $total = $rows->count() < self::MAX_HITS ? $rows->count() : $base->count();

        $scored = [];

        foreach ($rows as $row) {
            $scored[] = [
                'id' => (int) $row->id,
                'score' => $this->score($terms, (string) $row->title, (string) ($row->subtitle ?? '')),
                'sales' => (int) $row->sales_count,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return [$b['score'], $b['sales'], $b['id']] <=> [$a['score'], $a['sales'], $a['id']];
        });

        $ids = [];
        $scores = [];

        foreach ($scored as $item) {
            $ids[] = $item['id'];
            $scores[$item['id']] = $item['score'];
        }

        return new SearchResult($ids, $scores, $total, $mode === 'or', self::NAME);
    }

    /**
     * @param  list<string>  $terms
     */
    private function buildQuery(array $terms, string $mode): \Illuminate\Database\Eloquent\Builder
    {
        return Product::query()
            ->where('status', 1)
            ->where(function ($query) use ($terms, $mode): void {
                foreach ($terms as $index => $term) {
                    $pattern = '%'.SearchSuggester::escapeLike($term).'%';

                    // lower() 两侧各包一层：SQLite 的 LIKE 对 ASCII 天然不敏感，PG 的 LIKE
                    // 大小写敏感 —— 不包的话英文关键词「iphone」在 PG 降级链路会召回缺失
                    // （PG 回归 2026-09-24 定级 P1）。lower() 两种驱动都有，行为收敛为
                    // 「大小写不敏感」，与 S1-05-005 的契约一致；pattern 本身已小写归一。
                    $clause = function ($query) use ($pattern): void {
                        $query->whereRaw("lower(title) LIKE lower(?) ESCAPE '\\'", [$pattern])
                            ->orWhereRaw("lower(subtitle) LIKE lower(?) ESCAPE '\\'", [$pattern]);
                    };

                    // 第一个词永远是 AND（它要跟 status=1 并列），后续按 mode 决定连接符
                    if ($index === 0 || $mode !== 'or') {
                        $query->where($clause);
                    } else {
                        $query->orWhere($clause);
                    }
                }
            });
    }

    /**
     * 覆盖率分：标题命中 × 1.0 + 仅副标题命中 × 0.4，按 token 总数归一
     *
     * @param  list<string>  $terms
     */
    private function score(array $terms, string $title, string $subtitle): float
    {
        $titleHits = 0;
        $subtitleHits = 0;

        foreach ($terms as $term) {
            if ($this->contains($title, $term)) {
                $titleHits++;
            } elseif ($this->contains($subtitle, $term)) {
                $subtitleHits++;
            }
        }

        $raw = $titleHits * self::WEIGHT_TITLE + $subtitleHits * self::WEIGHT_SUBTITLE;

        return round($raw / count($terms), 6);
    }

    private function contains(string $haystack, string $needle): bool
    {
        return $haystack !== '' && mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
}
