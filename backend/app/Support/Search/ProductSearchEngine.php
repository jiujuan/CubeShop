<?php

namespace App\Support\Search;

/**
 * 商品检索引擎契约（站内搜索 S1-04）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.5 / §5.1
 *
 * 阶段一实现 `PostgresFtsEngine`，阶段二接 Meilisearch/ES —— **换引擎不改业务代码**，
 * 靠的是下面这几条硬约束（阶段二的前置条件，破坏任何一条都会让切换变成重写）：
 *
 * 1. Controller / Service 只依赖本接口与 DTO，**不得 import 任何引擎具体类**；
 * 2. 筛选（分类 / 品牌 / 价格 / 属性）**只允许写在 Eloquent**，不得下推到引擎；
 * 3. `search()` 只返回 `ids + scores`，排序语义统一为「相关度降序」；
 * 4. 所有引擎对同一数据集必须返回**相同 id 集合**（顺序可不同），由契约测试保证；
 * 5. 缓存 key 必须含 `name()`，避免切引擎后读到旧引擎的缓存。
 */
interface ProductSearchEngine
{
    /**
     * 引擎在当前环境是否可用（PG 驱动 / 扩展 / 第三方连通性）
     *
     * 不可用时由绑定兜底切到降级引擎，业务侧永远拿到可用实现。
     */
    public function isAvailable(): bool;

    /** 引擎标识（进缓存 key 与响应诊断） */
    public function name(): string;

    /**
     * 检索：返回按相关度降序的命中，仅 `ids + scores + total`
     *
     * ⚠️ 不做筛选、不做分页 —— 筛选是 Eloquent 的事（约束 2），
     * 分页交给 Service（引擎只给「最多 MAX 条候选」）。
     *
     * @throws \RuntimeException 引擎不可用时调用属于使用方 bug，直接抛而不是返回空，
     *                           否则会表现为「搜什么都搜不到」这种最难排查的故障
     */
    public function search(SearchCriteria $criteria): SearchResult;

    /**
     * 联想候选
     *
     * @return list<string>
     */
    public function suggest(string $keyword, int $limit): array;

    /** 单商品重建索引 */
    public function reindex(int $productId): void;

    /** 全量重建，返回处理条数 */
    public function reindexAll(int $chunk = 500, ?\Closure $progress = null): int;

    /** 从索引移除 */
    public function remove(int $productId): void;
}
