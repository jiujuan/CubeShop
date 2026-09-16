<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 优惠券模板（V1.1 F06 / T-031）
 *
 * 字段依据 docs/design/v1.1/CubeShop_V1.1_Backend_Design.md §1.2。
 * 发放采用「条件更新」原子防超发（见 CouponService），`issued_count` 只增不减（回退见 T-036）。
 */
class Coupon extends Model
{
    protected $table = 'coupons';

    /** 券类型 */
    public const TYPE_FIXED = 'fixed';      // 满减券：直减 amount
    public const TYPE_PERCENT = 'percent';  // 折扣券：按 percent 折扣，受 max_discount 封顶

    /** 适用范围 */
    public const SCOPE_ALL = 'all';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT = 'product';

    /** 有效期类型 */
    public const VALID_ABSOLUTE = 'absolute';  // 绝对时间窗口 valid_from ~ valid_to
    public const VALID_RELATIVE = 'relative';  // 领取后 valid_days 天

    /** 状态 */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_STOPPED = 'stopped';

    public const TYPE_LABELS = [
        self::TYPE_FIXED => '满减券',
        self::TYPE_PERCENT => '折扣券',
    ];

    public const SCOPE_LABELS = [
        self::SCOPE_ALL => '全场通用',
        self::SCOPE_CATEGORY => '指定分类',
        self::SCOPE_PRODUCT => '指定商品',
    ];

    protected $fillable = [
        'name', 'type', 'amount', 'percent', 'min_spend', 'max_discount',
        'scope', 'scope_refs', 'total_count', 'issued_count', 'used_count',
        'per_user_limit', 'valid_type', 'valid_from', 'valid_to', 'valid_days', 'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'scope_refs' => 'array',
        'percent' => 'integer',
        'total_count' => 'integer',
        'issued_count' => 'integer',
        'used_count' => 'integer',
        'per_user_limit' => 'integer',
        'valid_days' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];

    public function userCoupons(): HasMany
    {
        return $this->hasMany(UserCoupon::class, 'coupon_id');
    }

    /** 是否处于可领取状态（启用且未领完） */
    public function isReceivable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->issued_count < $this->total_count;
    }

    /** 绝对有效期是否覆盖当前时间（relative 类型不在此判定，领取时才计算） */
    public function isWithinWindow(): bool
    {
        $now = now();

        if ($this->valid_type !== self::VALID_ABSOLUTE) {
            return true;
        }

        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }

        return ! ($this->valid_to && $now->gt($this->valid_to));
    }
}
