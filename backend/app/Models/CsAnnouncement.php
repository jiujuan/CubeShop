<?php

namespace App\Models;

use App\Models\Traits\HasPublicId;
use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 服务公告（CS-201 / 供 CS-208 后台维护、CS-213 前台展示）
 *
 * `status` 生命周期：draft（草稿，不可见）→ published（已发布，前台可见）→ offline（已下架）。
 * 不做软删除（配置类数据）。
 *
 * 前台可见口径 = `status = published` 且 `published_at <= now()`（未到发布时间的定时公告不出现）。
 *
 * 正文：content_md 为 markdown 源（后台 md-editor-v3 编辑），content 是由模型 saving 钩子
 * 经 MarkdownRenderer 渲染 + HtmlSanitizer 白名单净化派生出的 HTML（与帮助中心文章、商品详情同一套不变式）。
 */
class CsAnnouncement extends Model
{
    use HasPublicId;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_OFFLINE = 'offline';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_PUBLISHED => '已发布',
        self::STATUS_OFFLINE => '已下架',
    ];

    protected $table = 'cs_announcement';

    protected $fillable = [
        'title', 'content', 'content_md', 'is_top', 'status', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_top' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // content_md 变更时派生 content(HTML)；未提供 content_md 时保留原 content（兼容存量行）
        static::saving(function (self $model): void {
            if ($model->isDirty('content_md') && $model->content_md !== null) {
                $model->content = HtmlSanitizer::clean(MarkdownRenderer::toHtml($model->content_md));
            }
        });
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** 前台可见：已发布且到达发布时间 */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** 前台排序：置顶优先，其次按发布时间倒序 */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_top')->orderByDesc('published_at')->orderByDesc('id');
    }
}
