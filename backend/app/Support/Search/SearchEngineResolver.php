<?php

namespace App\Support\Search;

/**
 * 搜索引擎解析（站内搜索 S1-06）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.4 降级链 / §5.1 约束 1
 *
 * 存在的理由是一个具体的两难：降级链第 0 步要求「单字中文查询直接走 LIKE 引擎」，
 * 但 §5.1 约束 1 又规定 **Service 不得 import 任何引擎具体类**。
 * 只靠容器绑定 `ProductSearchEngine` 无法表达「我要的是降级引擎而不是当前引擎」，
 * 于是把「按名字取引擎」收敛到这里 —— Service 依赖本类，仍然看不到任何具体引擎。
 *
 * `AppServiceProvider` 对 `ProductSearchEngine` 的绑定也委托给 {@see self::primary()}，
 * 保证「默认拿到的引擎」与「降级链选出来的引擎」是同一套判定，不会漂移。
 */
final class SearchEngineResolver
{
    private ?FallbackLikeEngine $like = null;

    private ?PostgresFtsEngine $postgres = null;

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly SearchIndexWriter $writer,
        private readonly SearchSuggester $suggester,
    ) {
    }

    /**
     * 当前应使用的引擎（按配置选择，不可用则降级）
     *
     * 与 S1-05 的绑定逻辑逐字一致：未实现的引擎名（阶段二的 meilisearch/elasticsearch）
     * 落进 default，配置写错只降级不 500。
     */
    public function primary(): ProductSearchEngine
    {
        $engine = match (config('services.search.engine')) {
            'off', 'like' => $this->like(),
            default => $this->postgres(),
        };

        return $engine->isAvailable() ? $engine : $this->like();
    }

    /** LIKE 降级引擎（降级链第 0 步的落点，也是 primary 兜底用实现） */
    public function like(): ProductSearchEngine
    {
        return $this->like ??= new FallbackLikeEngine($this->tokenizer, $this->writer, $this->suggester);
    }

    /** PG 原生全文检索引擎 */
    public function postgres(): ProductSearchEngine
    {
        return $this->postgres ??= new PostgresFtsEngine($this->tokenizer, $this->writer, $this->suggester);
    }
}
