<?php

namespace App\Models;

use App\Casts\MediaPath;
use App\Jobs\Search\ReindexProductsByBrandOrCategory;
use App\Models\Traits\HasPublicId;
use App\Models\Traits\ReleasesMediaOnDelete;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 品牌（V1.1 E01 / T-007）
 */
class Brand extends Model
{
    use HasPublicId, ReleasesMediaOnDelete;
    protected $table = 'brands';

    protected $fillable = ['name', 'logo', 'sort', 'status'];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
        'logo' => MediaPath::class,
    ];

    /**
     * 改名级联：品牌名进了商品检索列，改名等于该品牌下所有商品的索引都变了
     *
     * 只认 `name` 变更（改排序/状态不触发），丢给队列异步跑，后台保存不被拖住。
     */
    protected static function booted(): void
    {
        static::saved(function (self $brand): void {
            // ⚠️ 不要用 `wasRecentlyCreated` 判「是不是新建」：它只在插入时置 true，
            // 之后永不重置，同一个实例上的后续改名会被永久误判成新建。
            // 新建品牌名下没有商品，Job 里自然迭代 0 行，不必额外挡。
            if (! $brand->wasChanged('name')) {
                return;
            }

            if (! app(SearchIndexWriter::class)->taxonomyNamesEnabled()) {
                return;
            }

            ReindexProductsByBrandOrCategory::dispatch(
                ReindexProductsByBrandOrCategory::TYPE_BRAND,
                (int) $brand->getKey(),
            );
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }
}
