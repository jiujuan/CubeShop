<?php

namespace App\Support\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 搜索索引字段写入器（站内搜索 S1-02）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2 / §4.3
 *
 * 唯一职责：把商品拼成两个「待检索文本」列，供 PG 生成列 `search_vector` 消费：
 * - `search_title`（权重 A）：仅 `title`
 * - `search_body` （权重 B）：副标题 / 搜索关键词 / 全部 SKU 编码 / 品牌名 / 分类名 / 参数值
 *
 * ⚠️ 三条不变式，改动前先读：
 * 1. **各来源必须以空格拼接**。分词器把空白当作切分边界，`小米` + `手机` 拼成 `小米手机`
 *    会额外产出 `米手` 这种跨来源假词；拼成 `小米 手机` 就只有 `小米`、`手机`。
 * 2. **不写 SQL、不碰 `search_vector`**。向量由 PG 生成列自动派生，SQLite 下没有这列，
 *    应用层只负责这两个 text 列 —— 这是「测试跑 SQLite、生产跑 PG」能共存的前提。
 * 3. **纯函数式**：同样输入永远得到同样输出，故存量回填可重复执行且幂等。
 */
final class SearchIndexWriter
{
    /** 标题域截断长度（字符数，防 GIN 膨胀） */
    public const TITLE_MAX_LENGTH = 1000;

    /** 正文域截断长度（字符数） */
    public const BODY_MAX_LENGTH = 4000;

    /** 批量重算默认分块大小 */
    public const CHUNK = 500;

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly SearchConfig $config,
    ) {
    }

    /**
     * 一次性算出两个索引列
     *
     * @return array{search_title: string, search_body: string}
     */
    public function fields(Product $product): array
    {
        return [
            'search_title' => $this->buildTitleField($product),
            'search_body' => $this->buildBodyField($product),
        ];
    }

    /** 标题域：只放 title，权重最高 */
    public function buildTitleField(Product $product): string
    {
        return $this->tokenizer->tokenize(
            $this->plain($product->getAttribute('title')),
            self::TITLE_MAX_LENGTH,
        );
    }

    /**
     * 正文域：副标题 / 关键词 / SKU 编码 / 品牌名 / 分类名 / 参数值
     *
     * 品牌名与分类名由开关 `search.index_taxonomy_names` 控制（默认开）：
     * 关掉后改品牌/分类名不再需要级联重算该品牌下的全部商品。
     */
    public function buildBodyField(Product $product): string
    {
        $parts = [
            $this->plain($product->getAttribute('subtitle')),
            $this->plain($product->getAttribute('keywords')),
        ];

        if ($this->taxonomyNamesEnabled()) {
            $parts[] = $this->plain($product->brand?->getAttribute('name'));
            $parts[] = $this->plain($product->category?->getAttribute('name'));
        }

        $parts[] = $this->skuCodes($product);
        $parts[] = $this->attributeValues($product);

        return $this->tokenizer->tokenize(
            trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''))),
            self::BODY_MAX_LENGTH,
        );
    }

    /**
     * 重算单个商品的索引列（供属性/SKU 变更、品牌/分类改名级联调用）
     *
     * ⚠️ 两个刻意的取舍：
     * - **总是从库里重新载入**（哪怕传进来的是模型实例）：调用方手上的 `$product->skus`
     *   很可能是 SKU 写入前的旧缓存，直接用会算出缺 SKU 编码的索引；
     * - **走 query builder 直接改**，不经过模型 save：不触发 saving 派生（会绕一圈），
     *   也不刷 `updated_at`（否则"改个品牌名把商品更新时间全刷一遍"）。
     */
    public function reindex(Product|int $product): void
    {
        $id = $product instanceof Product ? $product->getKey() : $product;

        if ($id === null) {
            return;
        }

        $fresh = $this->loadForIndex((int) $id);

        if ($fresh === null) {
            return;
        }

        DB::table('products')->where('id', $fresh->getKey())->update($this->fields($fresh));
    }

    /**
     * 批量重算（存量回填 / 品牌分类级联 / search:reindex 共用同一份逻辑）
     *
     * 幂等：值未变化的行不写回，可重复执行、中途失败可直接重跑。
     *
     * @param  Builder<Product>  $query  调用方自行决定是否 `withTrashed()`
     * @param  ?\Closure(int,int):void  $progress  每块结束回调 `(已扫描, 已写回)`
     * @return int 实际写回的行数
     */
    public function reindexQuery(Builder $query, int $chunk = self::CHUNK, ?\Closure $progress = null): int
    {
        $updated = 0;
        $scanned = 0;

        $query->with(self::INDEX_RELATIONS)
            ->chunkById($chunk, function (Collection $products) use (&$updated, &$scanned, $progress): void {
                foreach ($products as $product) {
                    $scanned++;

                    $fields = $this->fields($product);

                    if ($fields['search_title'] === $product->search_title
                        && $fields['search_body'] === $product->search_body) {
                        continue;
                    }

                    DB::table('products')->where('id', $product->getKey())->update($fields);
                    $updated++;
                }

                // 每块结束回调一次（已扫描, 已写回），供命令行输出进度
                if ($progress !== null) {
                    $progress($scanned, $updated);
                }
            }, 'id');

        return $updated;
    }

    /**
     * 清空索引两列（PG 下生成列随之变空 tsvector，该商品不再被召回）
     *
     * 不是「删商品」，行本身不受影响 —— 想恢复调 {@see self::reindex()} 重算即可。
     * 两个引擎的 `remove()` 都走这里，保证切引擎不会留下半清的索引。
     */
    public function clear(int $productId): void
    {
        DB::table('products')
            ->where('id', $productId)
            ->update(['search_title' => null, 'search_body' => null]);
    }

    /** 带齐索引所需关联地取回商品（避免 N+1） */
    public function loadForIndex(int $productId): ?Product
    {
        return Product::query()
            ->withTrashed()
            ->with(self::INDEX_RELATIONS)
            ->find($productId);
    }

    /** 索引字段构建所依赖的关联，预加载清单（回填与级联共用） */
    public const INDEX_RELATIONS = [
        'brand:id,name',
        'category:id,name',
        'skus:id,product_id,sku_code',
        'attributeValues:id,product_id,value',
    ];

    /** 品牌名/分类名是否计入索引（默认开；关掉可消除改名的级联重算） */
    public function taxonomyNamesEnabled(): bool
    {
        // 走 SearchConfig（库 → env）：后台改的开关要立刻生效，不能只认 .env
        return in_array(
            strtolower((string) $this->config->get('search.index_taxonomy_names', '1')),
            ['1', 'true', 'on', 'yes'],
            true,
        );
    }

    /** @return list<string> 索引所需的全部关联名（供 chunk 预加载） */
    private function skuCodes(Product $product): string
    {
        return $this->joinValues($product->skus, 'sku_code');
    }

    private function attributeValues(Product $product): string
    {
        return $this->joinValues($product->attributeValues, 'value');
    }

    /**
     * 取关联集合里的某列并拼成文本
     *
     * 用属性访问而非 `getRelation()`：后者在关联未预加载时会读不存在的数组键，
     * 属性访问则按需惰性加载（回填与级联都预加载过，这里只是兜底）。
     */
    private function joinValues(mixed $relation, string $column): string
    {
        if (! $relation instanceof Collection) {
            return '';
        }

        $values = [];

        foreach ($relation as $row) {
            $value = $this->plain($row->getAttribute($column));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(' ', $values);
    }

    /**
     * 去 HTML 与反转义
     *
     * 分词器只认纯文本：带标签进来会把 `<div>` 切成 `div` 这类噪音 token，
     * 带实体进来会让 `&amp;` 变成 `amp`。
     */
    private function plain(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
