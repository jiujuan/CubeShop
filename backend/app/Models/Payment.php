<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 支付记录（数据库设计 2.7）
 */
class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CLOSED = 'closed';

    public const CHANNEL_WECHAT = 'wechat';
    public const CHANNEL_ALIPAY = 'alipay';

    /** 状态中文名（后台展示） */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待支付',
        self::STATUS_SUCCESS => '支付成功',
        self::STATUS_FAILED => '支付失败',
        self::STATUS_CLOSED => '已关闭',
    ];

    /** 渠道中文名（后台展示） */
    public const CHANNEL_LABELS = [
        self::CHANNEL_WECHAT => '微信支付',
        self::CHANNEL_ALIPAY => '支付宝',
    ];

    protected $table = 'payments';
    protected $fillable = [
        'payment_no', 'order_id', 'order_no', 'user_id',
        'channel', 'amount', 'status', 'channel_trade_no', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class, 'payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getChannelLabelAttribute(): string
    {
        return self::CHANNEL_LABELS[$this->channel] ?? $this->channel;
    }
}
