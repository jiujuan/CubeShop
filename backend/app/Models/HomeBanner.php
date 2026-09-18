<?php

namespace App\Models;

use App\Models\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 首页广告位（P-HomeBanner / 供后台 home.manage 维护、前台首页展示）
 *
 * position 三块：
 * - banner  ：主轮播图（多张轮播）
 * - promo   ：banner 下方广告图宫格（4 个：手机数码/家居生活/美妆个护/新用户福利）
 * - bottom  ：页面底部广告图（2 张）
 *
 * 前台可见口径 = is_enabled = true，按 position 分组、sort_order 升序输出。
 */
class HomeBanner extends Model
{
    use HasPublicId;

    public const POSITION_BANNER = 'banner';
    public const POSITION_PROMO = 'promo';
    public const POSITION_BOTTOM = 'bottom';

    public const POSITIONS = [
        self::POSITION_BANNER,
        self::POSITION_PROMO,
        self::POSITION_BOTTOM,
    ];

    public const POSITION_LABELS = [
        self::POSITION_BANNER => '主轮播图',
        self::POSITION_PROMO => '中部广告位',
        self::POSITION_BOTTOM => '底部广告位',
    ];

    protected $fillable = [
        'position', 'image', 'title', 'subtitle', 'link_url', 'sort_order', 'is_enabled', 'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public function positionLabel(): string
    {
        return self::POSITION_LABELS[$this->position] ?? $this->position;
    }

    /** 前台可见：已启用 */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** 前台排序：sort_order 升序（小者在前），同序按创建先后 */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
