<?php

namespace App\Models;

use App\Casts\MediaNested;
use App\Casts\MediaPath;
use App\Casts\MediaRichText;
use App\Models\Product;
use App\Models\Traits\ReleasesMediaOnDelete;
use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;
use App\Support\MediaUrl;
use App\Support\ProductEmbed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 帮助中心文章（CS-101 / CS-102）
 *
 * status：draft=草稿（用户端不可见）/ published=已发布 / offline=已下架
 *
 * 正文是**两列一对**（设计文档 §4：支持图片、表格、锚点）：
 * - `content_md`：markdown 源，后台用 md-editor-v3 编辑，是唯一可编辑载体；
 * - `content`   ：渲染后的 HTML 产物，用户端与后台预览都用 v-html 渲染。
 *
 * 一致性由 `booted()` 的 saving 钩子保证：只要本次写入动了 `content_md`，`content`
 * 就重新由 `MarkdownRenderer` 渲染、再过 `HtmlSanitizer` 白名单派生 —— 因此
 * 「落库的 HTML 一定经过白名单」这一不变式仍然成立，且渲染只发生在这一个入口
 * （接口 / Seeder / tinker 全覆盖）。
 *
 * 直接写 `content`（HTML 富文本）的旧路径保留净化兜底（见 `setContentAttribute()`），
 * 供存量清洗迁移使用；接口层不再暴露该入参，避免出现两套正文写法。
 */
class CsFaqArticle extends Model
{
    use ReleasesMediaOnDelete;
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_OFFLINE = 'offline';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_PUBLISHED => '已发布',
        self::STATUS_OFFLINE => '已下架',
    ];

    protected $table = 'cs_faq_article';

    protected $fillable = [
        'category_id', 'title', 'slug', 'summary', 'seo_title', 'seo_keywords', 'seo_description',
        'tags', 'content_md', 'content', 'sort',
        'is_hot', 'status', 'view_count', 'helpful_count', 'unhelpful_count', 'published_at',
        'page_fields', 'cover_image', 'blocks',
    ];

    protected $casts = [
        'is_hot' => 'boolean',
        'sort' => 'integer',
        'view_count' => 'integer',
        'helpful_count' => 'integer',
        'unhelpful_count' => 'integer',
        'published_at' => 'datetime',
        'page_fields' => MediaNested::class,
        'blocks' => MediaNested::class,
        'tags' => 'array',
        // 媒体治理 P0：封面图与正文内联图的相对路径换算（详见 App\Casts\MediaPath）
        'cover_image' => MediaPath::class,
        'content_md' => MediaRichText::class,
        'content' => MediaRichText::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CsFaqCategory::class, 'category_id');
    }

    /**
     * 关联的种草商品（多对多，经 cs_faq_article_product 中间表）
     *
     * 仅取上架商品，按关联 sort 排序；用作新闻详情页的「相关商品」与商品详情页的「相关资讯」反查。
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'cs_faq_article_product', 'article_id', 'product_id')
            ->withPivot('sort')
            ->orderBy('cs_faq_article_product.sort')
            ->orderBy('products.id');
    }

    /**
     * slug 语义化解析：优先 slug，命中不到再用整数 id 兜底（向后兼容 /news/{id}）
     *
     * 返回 null 表示既不是有效 slug 也不是正整数（调用方转 404）。
     */
    public static function findBySlugOrId(string $key): ?self
    {
        if (ctype_digit($key)) {
            return self::query()->find((int) $key);
        }

        return self::query()->where('slug', $key)->first();
    }

    /**
     * 标签数组（tags 列可能为 null 或脏数据）——统一给前端一个干净的字符串数组
     */
    public function tagList(): array
    {
        $tags = $this->tags;
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($t) => is_string($t) ? trim($t) : '',
            $tags,
        ), fn ($t) => $t !== ''));
    }

    /**
     * tags 录入归一：逗号/空白分隔的字符串 → 去重后的字符串数组（空 → null）
     *
     * 后台以「逗号分隔」录入，后端不存重复/空标签；null 表示无标签。
     */
    public static function normalizeTags(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $text = is_array($raw) ? implode(',', $raw) : (string) $raw;
        $tags = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[,，\s]+/u', $text) ?: []),
            fn ($t) => $t !== '',
        )));

        return $tags === [] ? null : $tags;
    }

    /**
     * markdown 源 → HTML 产物：正文渲染的唯一入口
     *
     * 三步固定顺序：渲染 → 内联商品标记转占位容器（ProductEmbed）→ 白名单净化。
     * 占位容器排在净化**之前**，是为了让它也走一遍白名单 —— `<div id>` 本来就在白名单内，
     * 因此「落库的 HTML 一定经过白名单」的不变式继续成立（见 App\Support\ProductEmbed）。
     */
    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            // 只在「本次写入动了 content_md」时派生，避免无关保存（如仅改状态）白跑一遍渲染；
            // 用 isDirty 而不是「属性是否存在」：从库里读出来的行属性里也有 content_md，
            // 若按存在判断，任何保存都会覆盖掉显式设置的 content（旧路径会被静默吃掉）。
            if (! $article->isDirty('content_md')) {
                return;
            }

            // 写原始 attributes 而不走 setter：派生产物不需要再过一次 cast；
            // 但正文里有内联图片，须归一成根相对，否则域名被写死回库里（媒体治理 P0）。
            $article->attributes['content'] = MediaUrl::normalizeEmbedded(
                HtmlSanitizer::cleanHtml(
                    ProductEmbed::tokenize(
                        MarkdownRenderer::toHtml((string) $article->getAttribute('content_md'))
                    )
                )
            );
        });
    }

    /**
     * 直接以 HTML 富文本写入时的净化兜底（白名单 + 协议校验）
     *
     * 注意走的是 `clean()`（含纯文本/行内片段启发判断），不是 `cleanHtml()` ——
     * 本入口的输入是「作者手写的 HTML」，允许是纯文本；而 markdown 渲染产物走
     * `cleanHtml()`（见 saving 钩子），两者混用会导致实体二次转义。
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = MediaUrl::normalizeEmbedded(HtmlSanitizer::clean($value));
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * 有帮助率 = 有帮助 / (有帮助 + 无帮助)；无反馈时返回 null（前端展示「—」）
     */
    public function helpfulRate(): ?float
    {
        $total = $this->helpful_count + $this->unhelpful_count;

        return $total > 0 ? round($this->helpful_count / $total, 4) : null;
    }
}
