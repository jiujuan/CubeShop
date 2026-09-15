<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 订单主表（数据库设计 2.6）
 */
class Order extends Model
{
    /** 订单状态（API 文档 10） */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDING = 'refunding';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * 状态机：允许的状态流转（Roadmap P4 / API 文档 10）
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_PAYMENT => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_SHIPPED, self::STATUS_REFUNDING, self::STATUS_CANCELLED],
        self::STATUS_SHIPPED => [self::STATUS_COMPLETED, self::STATUS_REFUNDING],
        self::STATUS_COMPLETED => [self::STATUS_REFUNDING],
        self::STATUS_REFUNDING => [self::STATUS_REFUNDED, self::STATUS_PAID],
        self::STATUS_REFUNDED => [],
        self::STATUS_CANCELLED => [],
    ];

    /** 状态中文映射 */
    public const STATUS_LABELS = [
        self::STATUS_PENDING_PAYMENT => '待支付',
        self::STATUS_PAID => '已支付',
        self::STATUS_SHIPPED => '已发货',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_REFUNDING => '退款中',
        self::STATUS_REFUNDED => '已退款',
    ];

    protected $table = 'orders';
    protected $fillable = [
        'order_no', 'user_id', 'status',
        'total_amount', 'freight_amount', 'pay_amount',
        'address_snapshot', 'remark',
        'paid_at', 'shipped_at', 'completed_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'address_snapshot' => 'array',
        'total_amount' => 'decimal:2',
        'freight_amount' => 'decimal:2',
        'pay_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }

    /** 状态流转是否合法 */
    public function canTransitTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** 是否待支付 */
    public function isPendingPayment(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT;
    }
}
