<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * WMS 对接配置（WMS 计划 P0 / F2、F3、D6）
 *
 * 凭证安全（SEC-01）：
 * - `app_secret_enc` / `access_token_enc` 落库为 `Crypt::encryptString()` 密文；
 * - 通过 `app_secret` / `access_token` 虚拟属性读写，**读到的只有代码内部可用**，
 *   接口出口一律用 {@see maskedAppSecret()} 掩码，绝不返回明文；
 * - 掩码形如 `****abcd`（仅末 4 位），且当值为空时返回 null（区别于"已配置但掩码"）。
 */
class WmsConfig extends Model
{
    protected $fillable = [
        'warehouse_id', 'provider', 'enabled', 'auto_push', 'auto_push_return',
        'push_retry_times', 'sku_mapping_mode',
        'app_key', 'customer_id', 'owner_no', 'warehouse_code', 'warehouse_no',
        'api_env', 'callback_token', 'extra_config', 'remark',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'enabled' => 'boolean',
        'auto_push' => 'boolean',
        'auto_push_return' => 'boolean',
        'push_retry_times' => 'integer',
        'extra_config' => 'array',
    ];

    /** 密文字段本身不对外序列化，避免意外泄露 */
    protected $hidden = ['app_secret_enc', 'access_token_enc'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    // ---------------- 凭证：虚拟属性读写（自动加解密） ----------------

    /** AppSecret 明文（仅后端内部使用，禁止出口） */
    protected function appSecret(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->decrypt($this->attributes['app_secret_enc'] ?? null),
            set: fn (?string $value) => ['app_secret_enc' => $this->encrypt($value)],
        );
    }

    /** access_token 明文（仅后端内部使用，禁止出口） */
    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->decrypt($this->attributes['access_token_enc'] ?? null),
            set: fn (?string $value) => ['access_token_enc' => $this->encrypt($value)],
        );
    }

    /** 是否已配置 AppSecret（判断"留空不覆盖"与"从未配置"） */
    public function hasAppSecret(): bool
    {
        return ! empty($this->attributes['app_secret_enc']);
    }

    public function hasAccessToken(): bool
    {
        return ! empty($this->attributes['access_token_enc']);
    }

    /** AppSecret 掩码（出口用）：`****` + 末 4 位；未配置返回 null */
    public function maskedAppSecret(): ?string
    {
        return $this->mask($this->app_secret);
    }

    /** access_token 掩码（出口用） */
    public function maskedAccessToken(): ?string
    {
        return $this->mask($this->access_token);
    }

    private function mask(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $len = mb_strlen($plain);

        return '****'.($len > 4 ? mb_substr($plain, -4) : '');
    }

    private function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString($value);
    }

    private function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (\Throwable) {
            // 密钥轮换/数据损坏时不让整个配置页 500——视为未配置
            return null;
        }
    }
}
