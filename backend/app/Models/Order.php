<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 订单主表（数据库设计 2.6）
 */
class Order extends Model
{
    /** 订单状态（API 文档 10） */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING_SHIP = 'pending_ship';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDING = 'refunding';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * 状态机：允许的状态流转（Roadmap P4 / API 文档 10）
     *
     * 履约主链路：待支付 → 已支付 → 待发货 → 已发货 → 已完成
     * - `paid`（已支付）：货款到账。线上/线下支付成功后由系统写入；
     * - `pending_ship`（待发货）：已进入发货队列。默认由系统在支付成功后**自动**流转，
     *   异常滞留（如自动流转失败、退款被驳回回流到 paid）时由后台「受理备货」手动推进；
     * - `paid → shipped` 不再直达，必须先落到 `pending_ship`，保证发货队列口径唯一。
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_PAYMENT => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_PENDING_SHIP, self::STATUS_REFUNDING, self::STATUS_CANCELLED],
        self::STATUS_PENDING_SHIP => [self::STATUS_SHIPPED, self::STATUS_REFUNDING, self::STATUS_CANCELLED],
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
        self::STATUS_PENDING_SHIP => '待发货',
        self::STATUS_SHIPPED => '已发货',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_REFUNDING => '退款中',
        self::STATUS_REFUNDED => '已退款',
    ];

    /**
     * 订单列表分组 Tab → 状态集合（V1.1 E02-C / T-004）
     *
     * pending_review 在 T-015 评价表落地后由控制器精化（排除已全部评价的订单）。
     */
    public const TAB_STATUS_MAP = [
        'all' => null,
        'pending_payment' => [self::STATUS_PENDING_PAYMENT],
        // 买家视角不区分「已支付 / 待发货」：两者对买家都是「已付款、等发货」
        'pending_ship' => [self::STATUS_PAID, self::STATUS_PENDING_SHIP],
        'pending_receive' => [self::STATUS_SHIPPED],
        'pending_review' => [self::STATUS_COMPLETED],
        'after_sale' => [self::STATUS_REFUNDING, self::STATUS_REFUNDED],
    ];

    public const TAB_LABELS = [
        'all' => '全部',
        'pending_payment' => '待付款',
        'pending_ship' => '待发货',
        'pending_receive' => '待收货',
        'pending_review' => '待评价',
        'after_sale' => '退款售后',
    ];

    protected $table = 'orders';
    protected $fillable = [
        'order_no', 'user_id', 'status',
        'total_amount', 'freight_amount', 'pay_amount',
        'coupon_id', 'discount_amount', 'promotion_discount', 'amount_details',
        'address_snapshot', 'remark',
        'paid_at', 'shipped_at', 'completed_at', 'cancelled_at', 'cancel_reason',
        'auto_completed',
    ];

    protected $casts = [
        'address_snapshot' => 'array',
        'amount_details' => 'array',
        'total_amount' => 'decimal:2',
        'freight_amount' => 'decimal:2',
        'pay_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'promotion_discount' => 'decimal:2',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_completed' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'order_id');
    }

    /** 使用的券模板（V1.1 F06） */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    /** 本单核销的用户券（V1.1 F06；取消/退款时据此返还） */
    public function usedCoupon(): HasOne
    {
        return $this->hasOne(UserCoupon::class, 'used_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    /**
     * 前端按钮可用性（V1.1 E02-C / T-004）
     *
     * 前端按此字段控制「去支付/取消/确认收货/申请退款/评价/再次购买」的显隐，
     * 避免前端硬编码状态判断导致与后端状态机不一致。
     *
     * @return array<string, bool>
     */
    public function actions(): array
    {
        return [
            'can_pay' => $this->status === self::STATUS_PENDING_PAYMENT,
            'can_cancel' => $this->status === self::STATUS_PENDING_PAYMENT,
            'can_confirm' => $this->status === self::STATUS_SHIPPED,
            'can_refund' => in_array($this->status, [self::STATUS_PAID, self::STATUS_PENDING_SHIP, self::STATUS_SHIPPED, self::STATUS_COMPLETED], true),
            'can_review' => $this->status === self::STATUS_COMPLETED,
            // 再次购买对任何历史订单都可用（失效行由后端逐行跳过并返回原因）
            'can_rebuy' => true,
        ];
    }

    /** 状态中文名 */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
