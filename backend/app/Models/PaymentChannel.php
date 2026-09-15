<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 支付渠道配置（收银台方案 §4.1(1)）
 *
 * config 内敏感键以 Crypt::encryptString 加密后存储，读取一律走
 * PaymentChannelService::decryptedConfig()，禁止直接取 config 属性使用。
 */
class PaymentChannel extends Model
{
    public const CHANNEL_WECHAT = 'wechat';
    public const CHANNEL_ALIPAY = 'alipay';
    public const CHANNEL_BALANCE = 'balance';
    public const CHANNEL_OFFLINE = 'offline';
    public const CHANNEL_MOCK = 'mock';

    /** 默认渠道名与排序（首次进入后台时的基线，可改） */
    public const PRESETS = [
        self::CHANNEL_WECHAT => ['name' => '微信支付', 'sort' => 10],
        self::CHANNEL_ALIPAY => ['name' => '支付宝', 'sort' => 20],
        self::CHANNEL_BALANCE => ['name' => '余额支付', 'sort' => 30],
        self::CHANNEL_OFFLINE => ['name' => '线下转账', 'sort' => 40],
        self::CHANNEL_MOCK => ['name' => '本地模拟', 'sort' => 99],
    ];

    /**
     * 需要加密存储的敏感键（§5.3）
     */
    public const SENSITIVE_KEYS = [
        'api_v3_key',
        'merchant_private_key',
        'wechatpay_public_key',
        'private_key',
        'alipay_public_key',
    ];

    protected $table = 'payment_channels';

    protected $fillable = [
        'channel', 'name', 'enabled', 'sandbox', 'sort', 'config',
        'notify_url', 'return_url', 'remark', 'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sandbox' => 'boolean',
        'config' => 'array',
        'sort' => 'integer',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(SysUser::class, 'updated_by');
    }
}
