<?php

namespace App\Support\Search;

/**
 * 检索条件（站内搜索 S1-04）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.5
 *
 * 一个不可变的值对象：所有入参在 {@see self::fromArray()} 里一次性归一化，
 * 之后任何环节拿到的都是「已校验、可直接用」的条件 —— 缓存 key 也因此能稳定。
 *
 * ⚠️ 这里只有**检索**条件。分类/品牌/价格/属性这些筛选虽然也由本对象携带，
 * 但按 §5.1 约束 2，它们**只在 Eloquent 侧生效**，引擎查询里不出现。
 *
 * ⚠️ `category_id` / `brand_id` 是**内部 int 主键**：public_id → int 的解析在控制器
 * 用 `PublicId::resolve()` 完成（与现有 `/products` 一致），不进这个对象。
 */
final class SearchCriteria
{
    /** 排序白名单（未知值一律回落，避免把用户输入直接拼进 ORDER BY） */
    public const SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'sales_desc'];

    public const MIN_PAGE_SIZE = 1;

    public const MAX_PAGE_SIZE = 100;

    /** 引擎最多回多少条候选（防深翻页把 rank 计算拖垮） */
    public const MAX_HITS = 1000;

    /**
     * @param  string  $keyword  已 trim 的关键词，空串表示「无关键词」（此时引擎不该被调用）
     * @param  array<int, list<string>>  $attributeValues  属性筛选：`[attribute_id => [value, ...]]`，
     *                                                    同属性多值 OR、跨属性 AND
     */
    public function __construct(
        public readonly string $keyword = '',
        public readonly ?int $categoryId = null,
        public readonly ?int $brandId = null,
        public readonly array $attributeValues = [],
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly string $sort = 'relevance',
        public readonly int $page = 1,
        public readonly int $pageSize = 20,
    ) {
    }

    /**
     * 从请求参数归一化
     *
     * 入参键名与 `/products` 完全一致（keyword / category_id / brand_id / attribute_values /
     * min_price / max_price / sort / page / page_size），便于搜索页直接复用现有筛选参数。
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromArray(array $params): self
    {
        $keyword = trim((string) ($params['keyword'] ?? ''));

        return new self(
            keyword: $keyword,
            categoryId: self::positiveInt($params['category_id'] ?? null),
            brandId: self::positiveInt($params['brand_id'] ?? null),
            attributeValues: self::parseAttributeValues($params['attribute_values'] ?? []),
            minPrice: self::nonNegativeFloat($params['min_price'] ?? null),
            maxPrice: self::nonNegativeFloat($params['max_price'] ?? null),
            sort: self::normalizeSort($params['sort'] ?? null, $keyword),
            page: max(1, (int) ($params['page'] ?? 1)),
            pageSize: self::clampPageSize($params['page_size'] ?? null),
        );
    }

    /** 有关键词才谈得上相关度排序 */
    public function hasKeyword(): bool
    {
        return $this->keyword !== '';
    }

    /** 是否按相关度排序（引擎给的 id 顺序即相关度降序） */
    public function sortByRelevance(): bool
    {
        return $this->sort === 'relevance';
    }

    /**
     * 缓存签名：同一组条件恒得同一字符串
     *
     * 只做签名，不带 `search:v{n}:{engine}:` 前缀 —— 版本号与引擎名由缓存层拼，
     * 这样「切引擎」「重建索引」能单独失效而不必改 DTO。
     *
     * 稳定性靠两点：先归一化再序列化；关联数组一律 ksort，避免 `?a=1&b=2` 与
     * `?b=2&a=1` 打出两个 key 造成缓存穿透。
     */
    public function signature(): string
    {
        return md5((string) json_encode($this->normalized()));
    }

    /** @return array<string, mixed> 归一化后的条件（用于序列化与测试比对） */
    public function toArray(): array
    {
        return $this->normalized();
    }

    /**
     * 换一个关键词（AND→OR 放宽、同义词替换都在原条件上派生，其余筛选不变）
     */
    public function withKeyword(string $keyword): self
    {
        return new self(
            keyword: $keyword,
            categoryId: $this->categoryId,
            brandId: $this->brandId,
            attributeValues: $this->attributeValues,
            minPrice: $this->minPrice,
            maxPrice: $this->maxPrice,
            sort: $this->sort,
            page: $this->page,
            pageSize: $this->pageSize,
        );
    }

    /** @return array<string, mixed> */
    private function normalized(): array
    {
        $values = $this->attributeValues;
        ksort($values);

        foreach ($values as $attributeId => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $values[$attributeId] = $list;
        }

        return [
            'keyword' => $this->keyword,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'attribute_values' => $values,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'sort' => $this->sort,
            'page' => $this->page,
            'page_size' => $this->pageSize,
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function nonNegativeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return $float >= 0 ? $float : null;
    }

    private static function normalizeSort(mixed $value, string $keyword): string
    {
        $sort = is_string($value) ? trim($value) : '';

        if (in_array($sort, self::SORTS, true)) {
            return $sort;
        }

        // 无关键词时「相关度」没有意义，回落成与 /products 一致的默认序
        return $keyword !== '' ? 'relevance' : 'newest';
    }

    private static function clampPageSize(mixed $value): int
    {
        $size = (int) $value;

        if ($size <= 0) {
            return 20;
        }

        return min($size, self::MAX_PAGE_SIZE);
    }

    /**
     * 属性筛选解析：`attribute_values[]=<attribute_id>:<value>`
     *
     * 与现有 `/products` 逐行同语义：缺冒号、id 非正、值为空的行一律跳过（不报错，
     * 筛选条件就该「忽略脏输入」而不是让整个搜索 400）。
     *
     * @return array<int, list<string>>
     */
    private static function parseAttributeValues(mixed $raw): array
    {
        $byAttribute = [];

        foreach ((array) $raw as $pair) {
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

        return $byAttribute;
    }
}
