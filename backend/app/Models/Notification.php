<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 站内通知（V1.1 F02 / T-018）
 */
class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'content', 'link', 'is_read', 'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    /** 是否未读 */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
