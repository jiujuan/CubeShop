<?php

namespace App\Models;

use App\Support\Sms\SmsProvider;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * 短信渠道配置（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D1
 *
 * 每个服务商一行（provider 唯一），凭证沿用 `WmsConfig` 的加密模式：
 * - `access_key_secret` 是**虚拟属性**：读自动解密、写自动加密，真实列是 `access_key_secret_enc`；
 * - 密文列进 `$hidden`，接口出口一律用 {@see maskedAccessKeySecret()}（`****abcd`）；
 * - 解密失败（密钥轮换/数据损坏）返回 null 而不是抛异常，避免配置页整体 500。
 *
 * ⚠️ 全局最多一行 `is_enabled = true`，由 `Services\Sms\SmsService` 在事务内「先关旧、再开新」保证，
 *    模型层不做全局约束（跨库行为不一致，SQLite/PG 对部分唯一索引支持不同）。
 */
class SmsConfig extends Model
{
    protected $fillable = [
        'provider', 'name', 'access_key_id', 'sign_name',
        'region', 'extra', 'is_enabled', 'remark',
        // access_key_secret 是虚拟属性（写入即加密），不进 fillable 会被 fill() 静默丢弃；
        // 后台控制器用的是显式属性赋值，不受影响，这里为批量构造（种子/测试）放行。
        'access_key_secret',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'extra' => 'array',
    ];

    /** 密文字段本身不对外序列化，避免意外泄露 */
    protected $hidden = ['access_key_secret_enc'];

    // ---------------- 凭证：虚拟属性读写（自动加解密） ----------------

    /** AccessKey Secret 明文（仅后端内部使用，禁止出口） */
    protected function accessKeySecret(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->decrypt($this->attributes['access_key_secret_enc'] ?? null),
            set: fn (?string $value) => ['access_key_secret_enc' => $this->encrypt($value)],
        );
    }

    /** 是否已配置 Secret（用于区分「留空不覆盖」与「从未配置」） */
    public function hasAccessKeySecret(): bool
    {
        return ! empty($this->attributes['access_key_secret_enc']);
    }

    /** Secret 掩码（出口用）：`****` + 末 4 位；未配置返回 null */
    public function maskedAccessKeySecret(): ?string
    {
        $plain = $this->access_key_secret;

        if ($plain === null || $plain === '') {
            return null;
        }

        return '****'.(mb_strlen($plain) > 4 ? mb_substr($plain, -4) : '');
    }

    /**
     * 凭证是否齐备（工厂判定真实/Mock 的唯一依据）
     *
     * ⚠️ 比设计文档多要求了 `sign_name`：阿里云缺少签名时 API 必然返回
     * `isv.SMS_SIGNATURE_ILLEGAL`，此时若仍判定「齐备」而走真实渠道，
     * 生产环境会拿到一个「发出去了但其实没发」的错误结果。签名是发送必要条件，故纳入完备度。
     */
    public function credentialsComplete(): bool
    {
        return ! empty($this->access_key_id)
            && ! empty($this->access_key_secret)
            && ! empty($this->sign_name);
    }

    /** 缺失项清单（后台提示用，避免管理员对着「未启用」猜原因） */
    public function missingCredentials(): array
    {
        $missing = [];

        if (empty($this->access_key_id)) {
            $missing[] = 'access_key_id';
        }

        if (empty($this->access_key_secret)) {
            $missing[] = 'access_key_secret';
        }

        if (empty($this->sign_name)) {
            $missing[] = 'sign_name';
        }

        return $missing;
    }

    public function providerLabel(): string
    {
        return SmsProvider::label((string) $this->provider);
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
            // 密钥轮换 / 数据损坏时不让整个配置页 500 —— 视为未配置
            return null;
        }
    }
}
