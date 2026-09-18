<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\HasPublicId;
use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;

/**
 * 商品主表
 *
 * 详情正文是「两列一对」：
 * - `description_md`：markdown 源，后台用 md-editor-v3 编辑，是唯一可编辑载体；
 * - `description`   ：渲染后的 HTML 产物，用户端 DetailView 用 v-html 渲染。
 *
 * 一致性由 `booted()` 的 saving 钩子保证：只要本次写入动了 `description_md`，
 * `description` 就重新由 `MarkdownRenderer` 渲染、再过 `HtmlSanitizer` 白名单派生
 * （与帮助中心文章 CS-101 同一套不变式）。
 */
class Product extends Model
{
    use SoftDeletes, HasPublicId;

    protected $table = 'products';
    protected $fillable = [
        'category_id', 'title', 'subtitle', 'main_image', 'description', 'description_md',
        'price', 'status', 'sales_count', 'sort',
        // V1.1 E01
        'brand_id', 'weight', 'video_url', 'keywords',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'brand_id' => 'integer',
        'weight' => 'integer',
    ];

    /**
     * markdown 源 → HTML 产物：详情渲染的唯一入口
     *
     * 仅在「本次写入动了 description_md」时派生，避免无关保存（如仅改状态/价格）
     * 白跑一遍渲染；用 isDirty 而非「属性是否存在」，否则从库里读出的行会覆盖
     * 显式设置的 description。
     */
    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if (! $product->isDirty('description_md')) {
                return;
            }

            $product->attributes['description'] = HtmlSanitizer::cleanHtml(
                MarkdownRenderer::toHtml((string) $product->getAttribute('description_md'))
            );
        });
    }

    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** 品牌（V1.1 E01） */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** 商品参数值（V1.1 E01） */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
