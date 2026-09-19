<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WMS 回调业务幂等登记（WMS 计划 P3 / Step 1）
 *
 * 四元组 (provider, biz_no, msg_type, status_key) 唯一——同一事件重复推送时
 * 由唯一索引兜底跳过（第一层幂等是 `fulfillment_orders` 状态机）。
 *
 * ⚠️ `status_key` NOT NULL DEFAULT ''：唯一索引对 NULL 不生效，空串表达「无状态语义」。
 */
class WmsCallbackDedup extends Model
{
    protected $table = 'wms_callback_dedups';

    protected $fillable = [
        'provider', 'biz_no', 'msg_type', 'status_key', 'note', 'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // status_key 禁 NULL（唯一索引对 NULL 不生效）：写入前把 null 归一为空串
        static::creating(function (self $dedup) {
            $dedup->status_key = (string) ($dedup->status_key ?? '');
        });
    }
}
