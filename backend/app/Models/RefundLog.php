<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 退款事件日志（refund_logs，append-only）
 *
 * 与 refunds 主表的最终态快照互补，记录退款全链路每个重要节点的请求/响应/状态/操作方。
 */
class RefundLog extends Model
{
    public $timestamps = false;

    protected $table = 'refund_logs';

    protected $fillable = [
        'refund_id', 'type', 'channel', 'out_refund_no',
        'request', 'response', 'channel_status', 'actor_type', 'actor_id', 'note', 'created_at',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class, 'refund_id');
    }
}
