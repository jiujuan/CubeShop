<?php

namespace App\Jobs\Search;

use App\Models\Product;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 品牌/分类改名后级联重算商品检索列（站内搜索 S1-03）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2
 *
 * 背景：品牌名与分类名进了 `search_body`，于是「改一次品牌名」语义上等于
 * 「该品牌下所有商品的索引都变了」。这类改名是低频操作，但影响面是 1:N，
 * 因此放进队列而不是在 `Brand::saved` 里同步跑完 —— 后台保存不该被几百次
 * 分词拖住。
 *
 * 三个刻意的取舍：
 * - **只在 name 变化时派发**（派发点在模型钩子），改排序/改状态不会触发全量重算；
 * - **开关关闭直接返回**：`search.index_taxonomy_names=false` 时索引里根本没有
 *   品牌/分类名，改名对索引无影响，跑就是白跑；
 * - **仅重算直接挂在该品牌/分类下的商品**：分类树的上级改名不向下递归，
 *   因为商品只挂在叶子类目上，递归会带来无谓的乘法开销。
 */
class ReindexProductsByBrandOrCategory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_BRAND = 'brand';

    public const TYPE_CATEGORY = 'category';

    /** 幂等操作，失败重跑无害，但改名影响面可能很大，值得多试几次 */
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {
    }

    public function handle(SearchIndexWriter $writer): int
    {
        if (! $writer->taxonomyNamesEnabled()) {
            return 0;
        }

        $column = $this->type === self::TYPE_BRAND ? 'brand_id' : 'category_id';

        // withTrashed：软删商品可被恢复，恢复时索引不应是改名前的旧值
        return $writer->reindexQuery(
            Product::query()->withTrashed()->where($column, $this->id)
        );
    }
}
