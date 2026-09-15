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

    public const EVENT_CREATE = 'create';
    public const EVENT_CALLBACK = 'callback';
    public const EVENT_NOTIFY = 'notify';
    public const EVENT_CLOSE = 'close';
    /** 线下转账核账（通过/驳回） */
    public const EVENT_REVIEW = 'review';
    /** 主动查单补偿 */
    public const EVENT_QUERY = 'query';
    /** 退款 */
    public const EVENT_REFUND = 'refund';

    /** 事件中文名（后台展示） */
    public const EVENT_LABELS = [
        self::EVENT_CREATE => '创建支付单',
        self::EVENT_CALLBACK => '渠道回调',
        self::EVENT_NOTIFY => '异步通知',
        self::EVENT_CLOSE => '后台关闭',
        self::EVENT_REVIEW => '线下核账',
        self::EVENT_QUERY => '主动查单',
        self::EVENT_REFUND => '退款',
    ];

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

    public function getEventLabelAttribute(): string
    {
        return self::EVENT_LABELS[$this->event] ?? $this->event;
    }
}
