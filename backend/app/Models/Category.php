<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Jobs\Search\ReindexProductsByBrandOrCategory;
use App\Models\Traits\HasPublicId;
use App\Support\Search\SearchIndexWriter;

/**
 * 商品分类（二级）
 */
class Category extends Model
{
    use SoftDeletes, HasPublicId;

    protected $table = 'categories';
    protected $fillable = ['parent_id', 'name', 'sort', 'status'];

    /**
     * 子分类（二级）
     * ⚠️ 本模型用 SoftDeletes，关系查询自动排除已软删的子分类
     *    —— 子分类被删干净后父分类即可删除，不必等物理清理。
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * 改名级联：分类名进了商品检索列，改名等于该分类下所有商品的索引都变了
     *
     * 与 {@see Brand::booted()} 同机制：只认 `name` 变更，丢给队列异步跑。
     */
    protected static function booted(): void
    {
        static::saved(function (self $category): void {
            // ⚠️ 同 Brand：不要用 `wasRecentlyCreated` 判新建（它永不重置），
            // 新建分类名下没有商品，Job 会迭代 0 行。
            if (! $category->wasChanged('name')) {
                return;
            }

            if (! app(SearchIndexWriter::class)->taxonomyNamesEnabled()) {
                return;
            }

            ReindexProductsByBrandOrCategory::dispatch(
                ReindexProductsByBrandOrCategory::TYPE_CATEGORY,
                (int) $category->getKey(),
            );
        });
    }
}
