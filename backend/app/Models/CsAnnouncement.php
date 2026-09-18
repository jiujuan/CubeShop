<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 服务公告（CS-201 / 供 CS-208 后台维护、CS-213 前台展示）
 *
 * `status` 生命周期：draft（草稿，不可见）→ published（已发布，前台可见）→ offline（已下架）。
 * 不做软删除（配置类数据）。
 *
 * 前台可见口径 = `status = published` 且 `published_at <= now()`（未到发布时间的定时公告不出现）。
 */
class CsAnnouncement extends Model
{
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
        'title', 'content', 'is_top', 'status', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_top' => 'boolean',
        'published_at' => 'datetime',
    ];

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
