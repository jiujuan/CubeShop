<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'category_id', 'title', 'summary', 'content_md', 'content', 'sort',
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
        'page_fields' => 'array',
        'blocks' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CsFaqCategory::class, 'category_id');
    }

    /**
     * markdown 源 → HTML 产物：正文渲染的唯一入口
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

            $article->attributes['content'] = HtmlSanitizer::cleanHtml(
                MarkdownRenderer::toHtml((string) $article->getAttribute('content_md'))
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
        $this->attributes['content'] = HtmlSanitizer::clean($value);
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
