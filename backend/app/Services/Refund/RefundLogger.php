<?php

namespace App\Services\Refund;

use App\Models\Refund;
use App\Models\RefundLog;

/**
 * 退款事件日志统一收口
 *
 * 所有重要节点（申请/审核/调渠道/响应/回调/查单/重试/终态）通过本类写入 refund_logs，
 * 便于全链路可追溯、可复盘。
 */
class RefundLogger
{
    /** 事件类型常量 */
    public const TYPE_APPLY = 'apply';
    public const TYPE_APPROVE = 'approve';
    public const TYPE_CHANNEL_REQUEST = 'channel_request';
    public const TYPE_CHANNEL_RESPONSE = 'channel_response';
    public const TYPE_CHANNEL_CALLBACK = 'channel_callback';
    public const TYPE_QUERY = 'query';
    public const TYPE_RETRY = 'retry';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_FAILED = 'failed';
    public const TYPE_STATUS_CHANGE = 'status_change';

    public static function record(Refund $refund, string $type, array $payload = []): RefundLog
    {
        return RefundLog::create([
            'refund_id' => $refund->id,
            'type' => $type,
            'channel' => $payload['channel'] ?? $refund->channel,
            'out_refund_no' => $payload['out_refund_no'] ?? $refund->out_refund_no,
            'request' => $payload['request'] ?? null,
            'response' => $payload['response'] ?? null,
            'channel_status' => $payload['channel_status'] ?? null,
            'actor_type' => $payload['actor_type'] ?? null,
            'actor_id' => $payload['actor_id'] ?? null,
            'note' => $payload['note'] ?? null,
            'created_at' => now(),
        ]);
    }
}
