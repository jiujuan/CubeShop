<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 工单沟通消息（CS-101 / CS-102）
 *
 * sender_type：user=买家 / staff=客服 / system=系统
 * is_internal=true 为内部备注，**仅客服可见且不通知用户**（CS-105 统一过滤）。
 */
class CsTicketMessage extends Model
{
    public const SENDER_USER = 'user';
    public const SENDER_STAFF = 'staff';
    public const SENDER_SYSTEM = 'system';

    public const SENDER_LABELS = [
        self::SENDER_USER => '用户',
        self::SENDER_STAFF => '客服',
        self::SENDER_SYSTEM => '系统',
    ];

    /** 单条消息最多 9 张图片 */
    public const MAX_IMAGES = 9;

    protected $table = 'cs_ticket_message';

    /** 消息为只追加流水：不维护 updated_at，只维护 created_at */
    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id', 'sender_type', 'sender_id', 'content', 'images', 'is_internal',
    ];

    protected $casts = [
        'images' => 'array',
        'is_internal' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(CsTicket::class, 'ticket_id');
    }

    /** 是否由客服发出（内部备注只能由客服写） */
    public function isFromStaff(): bool
    {
        return $this->sender_type === self::SENDER_STAFF;
    }
}
