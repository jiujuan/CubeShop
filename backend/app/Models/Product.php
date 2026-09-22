<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\HasPublicId;
use App\Models\Traits\ReleasesMediaOnDelete;
use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;
use App\Support\MediaUrl;
use App\Casts\MediaPath;
use App\Casts\MediaRichText;

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
    use SoftDeletes, HasPublicId, ReleasesMediaOnDelete;

    protected $table = 'products';
    protected $fillable = [
        'category_id', 'title', 'subtitle', 'main_image', 'description', 'description_md',
        'price', 'status', 'sales_count', 'sort',
        // V1.1 E01
        'brand_id', 'weight', 'video_url', 'keywords',
        // 运费升级 Stage 2（T-053）：绑定运费模板（null = 全局默认）
        'freight_template_id',
        // 首页推荐（P-HomeRecommend）：勾选后在前台首页「产品推荐」栏展示
        'is_home_recommended',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'brand_id' => 'integer',
        'weight' => 'integer',
        'freight_template_id' => 'integer',
        'is_home_recommended' => 'boolean',
        // 媒体治理 P0：库里存相对路径，读写两端经 MediaUrl 换算（详见 App\Casts\MediaPath）
        'main_image' => MediaPath::class,
        'description' => MediaRichText::class,
        'description_md' => MediaRichText::class,
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

            // 写原始 attributes 而不走 setter：派生产物不需要再过一次 cast；
            // 但里面有内联图片，须归一成根相对，否则域名又被写死回库里。
            $product->attributes['description'] = MediaUrl::normalizeEmbedded(
                HtmlSanitizer::cleanHtml(
                    MarkdownRenderer::toHtml((string) $product->getAttribute('description_md'))
                )
            );
        });
    }

    /**
     * 商品引用的图片 = 自身媒体列（主图 / 详情正文）+ 相册子表
     *
     * ⚠️ 相册行（`product_images`）在商品软删时**仍然存在**，若不一起解除，
     * 相册图会一直被算作「在用」，永远进不了回收窗口。
     *
     * @return array<int, string>
     */
    public function referencedMediaPaths(): array
    {
        $paths = $this->mediaColumnPaths();

        foreach ($this->images()->pluck('url') as $url) {
            foreach (MediaUrl::extractPaths(is_string($url) ? $url : null) as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique(array_filter($paths)));
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

    /**
     * 关联的种草新闻（反向多对多，经 cs_faq_article_product）
     *
     * 只取**已发布**的新闻，按文章发布时间倒序；用于商品详情页的「相关资讯/种草」区块。
     */
    public function news(): BelongsToMany
    {
        return $this->belongsToMany(CsFaqArticle::class, 'cs_faq_article_product', 'product_id', 'article_id')
            ->where('status', CsFaqArticle::STATUS_PUBLISHED)
            ->withPivot('sort')
            ->orderBy('cs_faq_article_product.sort')
            ->orderByDesc('cs_faq_article.published_at')
            ->orderByDesc('cs_faq_article.id');
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
