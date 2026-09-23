<?php

namespace App\Support\Search;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * PG 原生全文检索引擎（站内搜索 S1-04）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4 / §4.5
 *
 * 吃的是 `products.search_vector`（由 `search_title`/`search_body` 派生的生成列），
 * 中文分词在 PHP 侧完成（S1-01），PG 侧只做「按空格切词 + 权重 + 排序」。
 *
 * 三条刻意的边界：
 * 1. **只回 id + 分数**（§5.1 约束 3）。筛选与分页都不在这里 —— 约束 2 要求筛选只写 Eloquent，
 *    否则阶段二换 Meilisearch 时这段 SQL 就得整个重写。
 * 2. **`status = 1` 是引擎里唯一的过滤条件**。它不随筛选变化，切引擎后语义也一致；
 *    其余条件（分类/品牌/价格/属性）一律交给 Service。
 * 3. **AND 无果自动放宽为 OR**（`relaxed=true`）。这是降级链第 2 步，放在引擎内是因为
 *    「要不要放宽」取决于本引擎的召回能力，只有它自己知道 AND 是否真的没结果。
 *
 * ⚠️ 不可用时 `search()` **抛异常而不是返回空**：绑定层保证业务侧拿到的引擎一定可用，
 * 走到这里说明是调用方的 bug；静默返回空会表现为「搜什么都搜不到」，是最难排查的故障形态。
 */
final class PostgresFtsEngine implements ProductSearchEngine
{
    public const NAME = 'postgres';

    /**
     * 固定用 `simple` 配置：不做词干还原、不查词典
     *
     * 文本在 PHP 侧已切成 bigram 这种「伪词」，再让 PG 走词干/停用词只会二次破坏。
     */
    private const CONFIG = 'simple';

    /** 引擎最多回多少条候选（防深翻页把 rank 计算拖垮） */
    private const MAX_HITS = SearchCriteria::MAX_HITS;

    /** 可用性探测结果（进程内缓存，与 CarrierCode::flushCache() 同体例） */
    private static ?bool $available = null;

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly SearchIndexWriter $writer,
        private readonly SearchSuggester $suggester,
    ) {
    }

    /** 仅供测试：可用性缓存跨用例泄漏时手动清（换驱动 / 建列之后） */
    public static function flushAvailabilityCache(): void
    {
        self::$available = null;
    }

    public function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return self::$available = false;
        }

        // 驱动对了但迁移没跑（或跑在没建生成列的旧库上）时也不能用，否则每条查询都是 500
        return self::$available = Schema::hasColumn('products', 'search_vector');
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'PostgresFtsEngine 不可用：需要 pgsql 驱动且 products.search_vector 生成列已建立'
            );
        }

        $tokens = $this->tokenizer->tokens($criteria->keyword);

        if ($tokens === []) {
            return SearchResult::empty(self::NAME);
        }

        // 降级链第 1 步：AND（精确率优先）
        $exact = $this->run($tokens, 'and');

        if (! $exact->isEmpty()) {
            return $exact;
        }

        // 降级链第 2 步：零结果才放宽为 OR。单词查询放宽没有意义（还是它自己）
        if (count($tokens) > 1) {
            $relaxed = $this->run($tokens, 'or');

            if (! $relaxed->isEmpty()) {
                return $relaxed;
            }
        }

        // 同义词（第 3 步）与推荐位（第 4 步）由 Service 层接手，引擎到此为止
        return SearchResult::empty(self::NAME, relaxed: count($tokens) > 1);
    }

    /**
     * 联想候选
     *
     * 优先级见 §4.7：① `search_keywords` 前缀命中 ② 商品标题 ③ 分类名 ④ 品牌名。
     * ⚠️ ① 依赖迁移 000121，表还不存在，故当前只实现 ②③④ —— 表建好后在最前面补 ① 即可。
     *
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

    /**
     * 从索引移除：清空两个源列，生成列随之变空 tsvector
     *
     * 不是「删商品」，也不影响行本身 —— 想恢复调 {@see self::reindex()} 即可重算。
     */
    public function remove(int $productId): void
    {
        $this->writer->clear($productId);
    }

    /**
     * 执行一次 tsquery 检索
     *
     * `count(*) OVER ()` 在 LIMIT 之前算，所以一次查询就能同时拿到候选与命中总数，
     * 不必为 total 再跑一遍（命中集大时那是实打实的一倍开销）。
     *
     * @param  list<string>  $tokens
     */
    private function run(array $tokens, string $mode, bool $prefix = false): SearchResult
    {
        $tsquery = self::buildTsquery($tokens, $mode, $prefix);

        if ($tsquery === '') {
            return SearchResult::empty(self::NAME);
        }

        $rows = DB::select(
            'SELECT id,'
            .' ts_rank_cd(search_vector, q, 32) AS score,'
            .' count(*) OVER () AS total'
            .' FROM products, to_tsquery(?, ?) q'
            .' WHERE status = 1 AND search_vector @@ q'
            .' ORDER BY score DESC, sales_count DESC, id DESC'
            .' LIMIT '.self::MAX_HITS,
            [self::CONFIG, $tsquery],
        );

        if ($rows === []) {
            return SearchResult::empty(self::NAME, relaxed: $mode === 'or');
        }

        $ids = [];
        $scores = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $ids[] = $id;
            $scores[$id] = (float) $row->score;
        }

        return new SearchResult($ids, $scores, (int) $rows[0]->total, $mode === 'or', self::NAME);
    }

    /**
     * 构造 tsquery 字面量（纯函数，可单测）
     *
     * ⚠️ token 一律包成单引号字面量：`& | ! ( ) : *` 在 tsquery 里都是语法字符，
     * 不转义轻则报语法错误、重则被当成操作符改变语义（用户搜 `C++` 直接 500）。
     * 内部单引号按 PG 规则写成两个单引号。
     *
     * 前缀匹配（`prefix=true`）只作用于**最后一个** token，形如 `'iphon':*`
     * —— `:*` 必须在引号**外面**，写进引号里就变成字面量冒号了。
     *
     * @param  list<string>  $tokens
     */
    public static function buildTsquery(array $tokens, string $mode = 'and', bool $prefix = false): string
    {
        $tokens = array_values(array_filter(
            array_map(static fn ($t): string => trim((string) $t), $tokens),
            static fn (string $t): bool => $t !== '',
        ));

        if ($tokens === []) {
            return '';
        }

        $last = count($tokens) - 1;
        $parts = [];

        foreach ($tokens as $index => $token) {
            $literal = "'".str_replace("'", "''", $token)."'";

            $parts[] = $prefix && $index === $last ? $literal.':*' : $literal;
        }

        return implode($mode === 'or' ? ' | ' : ' & ', $parts);
    }
}
