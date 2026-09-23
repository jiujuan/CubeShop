<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Collection;

/**
 * 一页搜索结果（站内搜索 S1-06）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §6
 *
 * 与 {@see SearchResult} 的分工：**那个是引擎的原始命中（id + 分数），这个是给用户看的一页**
 * —— 商品行、筛选后的真实总数、相关分类、推荐位。
 *
 * ⚠️ `total` 是**筛选后**的总数（可直接用于分页），引擎给的 `SearchResult::total` 未经筛选，
 * 只能当诊断信息。两者的差就是「命中的商品里有多少被当前筛选条件挡掉了」。
 *
 * @param  Collection<int, \App\Models\Product>  $items  当前页商品，已按最终顺序排好
 * @param  list<array{id: string, name: string, count: int}>  $relatedCategories  命中结果的分类聚合（public_id）
 * @param  Collection<int, \App\Models\Product>  $recommendations  空结果时的推荐位
 */
final class SearchPage
{
    public function __construct(
        public readonly Collection $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly bool $relaxed,
        public readonly string $engine,
        public readonly array $relatedCategories = [],
        public readonly Collection $recommendations = new Collection,
    ) {
    }

    public static function empty(SearchCriteria $criteria, string $engine, Collection $recommendations = new Collection): self
    {
        return new self(
            items: new Collection,
            total: 0,
            page: $criteria->page,
            pageSize: $criteria->pageSize,
            relaxed: false,
            engine: $engine,
            recommendations: $recommendations,
        );
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function lastPage(): int
    {
        return $this->pageSize > 0 ? (int) max(1, ceil($this->total / $this->pageSize)) : 1;
    }

    /**
     * 分页结构（与 `ApiResponse::paginated()` 逐字同形，便于前端复用同一套解析）
     *
     * @param  bool  $exposeTotal  为 false 时 total/total_pages 置 null（SEC-04：非后台不暴露精确总量）
     * @return array<string, mixed>
     */
    public function pagination(bool $exposeTotal = true): array
    {
        return [
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'total' => $exposeTotal ? $this->total : null,
            'total_pages' => $exposeTotal ? $this->lastPage() : null,
            'has_more' => $this->page < $this->lastPage(),
        ];
    }
}
