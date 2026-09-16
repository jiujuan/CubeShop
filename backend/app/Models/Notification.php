<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 站内通知（V1.1 F02 / T-018）
 *
 * receiver_type 区分收件人来源（user_id 为混合语义列）：
 * - customer：买家（orders/refund/review/password 等业务通知）
 * - admin：后台管理员（库存预警等运营通知）
 */
class Notification extends Model
{
    public const RECEIVER_CUSTOMER = 'customer';

    public const RECEIVER_ADMIN = 'admin';

    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'receiver_type', 'type', 'title', 'content', 'link', 'is_read', 'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'receiver_type' => 'string',
    ];

    /** 是否未读 */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /** 按收件人身份筛选 */
    public function scopeReceiver($query, string $receiverType)
    {
        return $query->where('receiver_type', $receiverType);
    }
}
