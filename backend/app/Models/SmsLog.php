<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 短信发送记录（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D5
 *
 * 两条硬约定：
 * 1. **手机号只存脱敏值**（`138****8000`），明文手机号绝不落库；
 * 2. **模板参数不落库** —— 验证码场景的参数就是验证码明文，落进去等于把验证码写进日志。
 *
 * `provider` 冗余存一份：渠道配置行被删除后，历史日志仍然可读。
 */
class SmsLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** 总开关关闭时未真实发送 */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'sms_config_id', 'provider', 'phone_masked', 'scene', 'template_code',
        'status', 'error_code', 'error_msg', 'biz_id', 'latency_ms',
    ];

    protected $casts = [
        'sms_config_id' => 'integer',
        'latency_ms' => 'integer',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(SmsConfig::class, 'sms_config_id');
    }
}
