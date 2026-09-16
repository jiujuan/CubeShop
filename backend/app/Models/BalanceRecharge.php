<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 余额充值单（收银台方案 §4.1(4)）
 *
 * 状态流转：pending → success | failed | closed；线下转账 pending → reviewing → success | failed
 */
class BalanceRecharge extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CLOSED = 'closed';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待支付',
        self::STATUS_REVIEWING => '待核账',
        self::STATUS_SUCCESS => '充值成功',
        self::STATUS_FAILED => '充值失败',
        self::STATUS_CLOSED => '已关闭',
    ];

    public const CHANNEL_WECHAT = 'wechat';
    public const CHANNEL_ALIPAY = 'alipay';
    public const CHANNEL_BALANCE = 'balance';
    public const CHANNEL_OFFLINE = 'offline';

    public const CHANNEL_LABELS = [
        self::CHANNEL_WECHAT => '微信支付',
        self::CHANNEL_ALIPAY => '支付宝',
        self::CHANNEL_BALANCE => '余额支付',
        self::CHANNEL_OFFLINE => '线下转账',
    ];

    protected $table = 'balance_recharges';

    protected $fillable = [
        'recharge_no', 'user_id', 'amount', 'gift_amount', 'channel', 'status', 'payment_id',
        'payer_name', 'payer_account', 'transfer_no', 'transferred_at', 'voucher_url',
        'review_remark', 'reviewed_by', 'reviewed_at', 'paid_at', 'expired_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gift_amount' => 'decimal:2',
        'transferred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getChannelLabelAttribute(): string
    {
        return self::CHANNEL_LABELS[$this->channel] ?? $this->channel;
    }

    /** 实收金额（本金 + 赠送） */
    public function getCreditAmountAttribute(): string
    {
        return number_format((float) $this->amount + (float) $this->gift_amount, 2, '.', '');
    }
}
