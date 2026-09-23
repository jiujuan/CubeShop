<?php

namespace App\Support\Search;

/**
 * 引擎检索结果（站内搜索 S1-04）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.5
 *
 * 只有三样东西：**命中 id（按相关度降序）**、**分数**、**引擎命中总数**。
 * 商品行、库存、价格一律不在里面 —— 那些由 Service 用 Eloquent 现取，
 * 保证读到的是最新值（缓存只缓存 id 列表，不缓存商品行）。
 *
 * ⚠️ `total` 是**引擎命中数**，未经分类/品牌/价格/属性筛选。
 * 最终分页的 total 由 Service 在 Eloquent 侧重算 —— 引擎不知道筛选条件（§5.1 约束 2），
 * 所以它给不出筛选后的总数，这个分工必须在调用处想清楚，否则会出现
 * 「total=86 但翻到第 3 页就空了」的经典分页错位。
 */
final class SearchResult
{
    /**
     * @param  list<int>  $ids  按相关度降序
     * @param  array<int, float>  $scores  `id => 相关度`，仅诊断/排序用，不对外暴露
     * @param  int  $total  引擎命中总数（未筛选）
     * @param  bool  $relaxed  是否经过「AND 无果 → OR」放宽（前端据此提示"已为你放宽匹配"）
     * @param  string  $engine  出结果的引擎名（诊断用）
     */
    public function __construct(
        public readonly array $ids,
        public readonly array $scores,
        public readonly int $total,
        public readonly bool $relaxed,
        public readonly string $engine,
    ) {
    }

    /** 空结果（无关键词、分词后无 token、或降级链走到底仍零命中） */
    public static function empty(string $engine, bool $relaxed = false): self
    {
        return new self([], [], 0, $relaxed, $engine);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /**
     * @return array{ids: list<int>, scores: array<int, float>, total: int, relaxed: bool, engine: string}
     */
    public function toArray(): array
    {
        return [
            'ids' => $this->ids,
            'scores' => $this->scores,
            'total' => $this->total,
            'relaxed' => $this->relaxed,
            'engine' => $this->engine,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_values(array_map('intval', (array) ($data['ids'] ?? []))),
            array_map('floatval', (array) ($data['scores'] ?? [])),
            (int) ($data['total'] ?? 0),
            (bool) ($data['relaxed'] ?? false),
            (string) ($data['engine'] ?? ''),
        );
    }
}
