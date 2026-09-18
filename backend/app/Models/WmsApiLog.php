<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WMS 接口调用日志（WMS 计划 P0，P2/P3 复用）
 *
 * 出入站报文留痕，支撑「可观测 / 可重放」。仅 created_at，无 updated_at。
 */
class WmsApiLog extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public const UPDATED_AT = null;

    protected $fillable = [
        'direction', 'provider', 'api_name', 'request_id', 'biz_no',
        'request_body', 'response_body', 'http_status', 'success', 'error_msg', 'created_at',
    ];

    protected $casts = [
        'request_body' => 'array',
        'response_body' => 'array',
        'http_status' => 'integer',
        'success' => 'boolean',
        'created_at' => 'datetime',
    ];
}
