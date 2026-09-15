<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 支付日志（数据库设计 2.7）：create / callback / notify / refund 相关事件
 */
class PaymentLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payment_logs';
    protected $fillable = ['payment_id', 'payment_no', 'event', 'request_data', 'response_data'];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
