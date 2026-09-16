<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户持有的优惠券（V1.1 F06 / T-031）
 *
 * `expire_at` 在领取时按券模板的 valid_type 计算并**固化**，后续调整券模板不影响已领券。
 */
class UserCoupon extends Model
{
    protected $table = 'user_coupons';

    public const STATUS_UNUSED = 'unused';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_RETURNED = 'returned';

    public const STATUS_LABELS = [
        self::STATUS_UNUSED => '未使用',
        self::STATUS_USED => '已使用',
        self::STATUS_EXPIRED => '已过期',
        self::STATUS_RETURNED => '已退回',
    ];

    /** 临近过期阈值（天），前端标红提示 */
    public const NEAR_EXPIRY_DAYS = 3;

    protected $fillable = [
        'user_id', 'coupon_id', 'status', 'used_order_id', 'used_at', 'expire_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expire_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'used_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** 是否已过期（按固化时间判定） */
    public function isExpired(): bool
    {
        return $this->expire_at !== null && $this->expire_at->lt(now());
    }

    /** 是否可用（未使用且未过期） */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_UNUSED && ! $this->isExpired();
    }

    /** 是否临近过期（≤ NEAR_EXPIRY_DAYS 天） */
    public function isNearExpiry(): bool
    {
        if ($this->status !== self::STATUS_UNUSED || $this->expire_at === null) {
            return false;
        }

        // 用时间戳差判定，避免依赖 Carbon diff 的有符号/绝对值语义差异
        $remaining = $this->expire_at->getTimestamp() - now()->getTimestamp();

        return $remaining >= 0 && $remaining <= self::NEAR_EXPIRY_DAYS * 86400;
    }
}
