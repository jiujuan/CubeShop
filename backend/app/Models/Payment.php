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
}
