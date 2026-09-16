<?php

namespace App\Services\Payment;

use App\Models\PaymentChannel;
use App\Services\Common\ConfigService;
use App\Services\Payment\BalanceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * 支付渠道配置服务（收银台方案 §5）
 *
 * 职责：
 * - 渠道可用性判定（渠道 enabled × system_configs 总开关）
 * - config 敏感键加密存储 / 解密读取 / 掩码回显
 * - 「编辑留空不覆盖」与变更字段名提取（供操作日志只记字段名）
 */
class PaymentChannelService
{
    public function __construct(private readonly ConfigService $config, private readonly BalanceService $balances)
    {
    }

    /**
     * 全部渠道（按排序），表为空时按预置基线初始化
     */
    public function all(): Collection
    {
        $this->ensurePresets();

        return PaymentChannel::query()->orderBy('sort')->orderBy('id')->get();
    }

    public function find(string $channel): ?PaymentChannel
    {
        $this->ensurePresets();

        return PaymentChannel::query()->where('channel', $channel)->first();
    }

    /** 预置渠道基线（首次使用或表为空时写入） */
    public function ensurePresets(): void
    {
        foreach (PaymentChannel::PRESETS as $channel => $preset) {
            PaymentChannel::query()->firstOrCreate(
                ['channel' => $channel],
                [
                    'name' => $preset['name'],
                    'sort' => $preset['sort'],
                    'enabled' => $this->presetEnabled($channel),
                    'sandbox' => in_array($channel, [PaymentChannel::CHANNEL_WECHAT, PaymentChannel::CHANNEL_ALIPAY], true),
                    'config' => [],
                    'notify_url' => $this->buildNotifyUrl($channel),
                ],
            );
        }
    }

    private function presetEnabled(string $channel): bool
    {
        if ($channel === PaymentChannel::CHANNEL_MOCK) {
            // 本地模拟只对本地/测试环境开放（控制器层另有生产硬校验）
            return app()->environment('local', 'testing');
        }

        return true;
    }

    /** 回调地址（只读展示 + 复制，后台可改后按库里的值走） */
    public function buildNotifyUrl(string $channel): string
    {
        return rtrim((string) config('app.url', 'http://127.0.0.1:8000'), '/').'/api/payments/callback/'.$channel;
    }

    /**
     * 渠道是否对前台开放（渠道开关 × 业务总开关）
     */
    public function isEnabled(string $channel): bool
    {
        $record = $this->find($channel);

        if (! $record) {
            return $this->defaultEnabled($channel);
        }

        return $record->enabled && $this->globalEnabled($channel);
    }

    /**
     * 未配置记录时的兜底（保持与 V1.1 沙箱行为兼容）
     */
    public function defaultEnabled(string $channel): bool
    {
        return match ($channel) {
            PaymentChannel::CHANNEL_MOCK => app()->environment('local', 'testing') || (bool) config('payments.sandbox', true),
            // 沙箱模式下微信/支付宝由 Mock 网关代理，视为可用；正式环境必须显式配置后才可用
            PaymentChannel::CHANNEL_WECHAT, PaymentChannel::CHANNEL_ALIPAY => (bool) config('payments.sandbox', true),
            PaymentChannel::CHANNEL_BALANCE => $this->globalEnabled($channel),
            PaymentChannel::CHANNEL_OFFLINE => $this->globalEnabled($channel),
            default => false,
        };
    }

    /** system_configs 业务总开关 */
    private function globalEnabled(string $channel): bool
    {
        return match ($channel) {
            PaymentChannel::CHANNEL_BALANCE => $this->config->get('payment.balance_enabled', '1') === '1',
            PaymentChannel::CHANNEL_OFFLINE => $this->config->get('payment.offline_enabled', '1') === '1',
            default => true,
        };
    }

    /** 渠道是否走沙箱（无记录时按 config('payments.sandbox')） */
    public function isSandbox(string $channel): bool
    {
        $record = $this->find($channel);

        return $record ? $record->sandbox : (bool) config('payments.sandbox', true);
    }

    /**
     * 商户参数是否配置齐全（后台列表「已配置」标记 / 工厂真实网关前置条件）
     */
    public function isConfigured(string $channel): bool
    {
        $config = $this->decryptedConfig($channel);

        $required = $channel === PaymentChannel::CHANNEL_WECHAT
            ? ['app_id', 'mch_id', 'api_v3_key', 'merchant_private_key', 'merchant_cert_serial_no']
            : ($channel === PaymentChannel::CHANNEL_ALIPAY
                ? ['app_id', 'private_key', 'alipay_public_key']
                : []);

        foreach ($required as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 已启用渠道列表（前台 GET /payments/channels 用）
     *
     * @param  string  $scene  order | recharge（recharge 过滤掉余额支付）
     * @return array<int, array>
     */
    public function enabledChannels(string $scene = 'order'): array
    {
        $this->ensurePresets();

        return $this->all()
            ->filter(fn (PaymentChannel $c) => $this->isEnabled($c->channel))
            ->reject(fn (PaymentChannel $c) => $scene === 'recharge' && $c->channel === PaymentChannel::CHANNEL_BALANCE)
            ->values()
            ->map(fn (PaymentChannel $c) => [
                'code' => $c->channel,
                'name' => $c->name,
                'sort' => $c->sort,
                'sandbox' => $c->sandbox,
            ])
            ->all();
    }

    /**
     * 收银台首屏渠道数据（§6.1 / §9.1）
     *
     * 在 enabledChannels 基础上按场景补充：
     * - balance 渠道附带当前用户余额（不足时前端置灰）
     * - offline 渠道附带单收款账户（开户行/户名/账号/收款码）
     * - recharge 场景过滤掉余额支付，并附带面额/赠送规则/限额
     *
     * @return array{default_channel: string, channels: array, recharge?: array}
     */
    public function cashierChannels(int $userId, string $scene = 'order'): array
    {
        $this->ensurePresets();

        $channels = collect($this->enabledChannels($scene))
            ->map(function (array $item) use ($userId) {
                if ($item['code'] === PaymentChannel::CHANNEL_BALANCE) {
                    $item['balance'] = $this->balances->balance($userId);
                }
                if ($item['code'] === PaymentChannel::CHANNEL_OFFLINE) {
                    $item['receipt'] = $this->offlineReceipt();
                }

                return $item;
            })
            ->values()
            ->all();

        $result = [
            'default_channel' => $this->defaultChannel($scene),
            'channels' => $channels,
        ];

        if ($scene === 'recharge') {
            $result['recharge'] = $this->rechargeConfig();
        }

        return $result;
    }

    /** 收银台默认选中渠道（配置缺失时回退微信） */
    private function defaultChannel(string $scene): string
    {
        $default = $this->config->get('payment.default_channel', PaymentChannel::CHANNEL_WECHAT);

        // recharge 场景禁用余额作为默认
        if ($scene === 'recharge' && $default === PaymentChannel::CHANNEL_BALANCE) {
            return PaymentChannel::CHANNEL_WECHAT;
        }

        return $default;
    }

    /** 充值页配置（面额 / 赠送规则 / 限额，§6.5） */
    private function rechargeConfig(): array
    {
        return [
            'enabled' => $this->config->get('payment.recharge_enabled', '1') === '1',
            'amounts' => $this->parseAmounts($this->config->get('payment.recharge_amounts', '50,100,200,500')),
            'min_amount' => $this->config->getDecimal('payment.recharge_min_amount', '10.00'),
            'max_single' => $this->config->getDecimal('payment.recharge_max_single', '5000.00'),
            'max_daily' => $this->config->getDecimal('payment.recharge_max_daily', '20000.00'),
            'gift_rules' => $this->parseGiftRules($this->config->get('payment.recharge_gift_rules', '[]')),
        ];
    }

    /** 固定面额：逗号分隔 → 数值字符串数组 */
    private function parseAmounts(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
            ->values()
            ->all();
    }

    /** 赠送规则：JSON 数组，容错为空 */
    private function parseGiftRules(string $raw): array
    {
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }

    /** 线下收款账户（单账户） */
    public function offlineReceipt(): array
    {
        $raw = $this->config->get('payment.offline_receipt', '{}');
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * 解密后的渠道配置（敏感键还原明文，供网关使用）
     */
    public function decryptedConfig(string $channel): array
    {
        $record = $this->find($channel);
        if (! $record) {
            return [];
        }

        $config = $record->config ?? [];
        foreach (PaymentChannel::SENSITIVE_KEYS as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
                try {
                    $config[$key] = Crypt::decryptString($config[$key]);
                } catch (\Throwable) {
                    // 历史明文数据：保持原值，避免配置丢失
                }
            }
        }

        // 回调/回跳地址与沙箱标志是独立列，合并进配置供网关直接取用
        $config['notify_url'] = $record->notify_url ?: $this->buildNotifyUrl($channel);
        $config['return_url'] = $record->return_url ?: '';
        $config['sandbox'] = $record->sandbox;

        return $config;
    }

    /**
     * 后台回显用配置：敏感键替换为掩码，并附带 has_xxx 布尔位
     */
    public function maskedConfig(string $channel): array
    {
        $config = $this->decryptedConfig($channel);
        $masked = $config;

        foreach (PaymentChannel::SENSITIVE_KEYS as $key) {
            $masked['has_'.$key] = ! empty($config[$key]);
            if (isset($config[$key])) {
                $masked[$key] = self::maskSecret((string) $config[$key]);
            }
        }

        return $masked;
    }

    /**
     * 密钥掩码：sk_live_****abcd（只保留后 4 位）
     */
    public static function maskSecret(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $tail = substr($value, -4);

        return '****'.$tail;
    }

    /**
     * 构造待保存的渠道配置：敏感键加密、留空/掩码表示不覆盖
     *
     * @param  array  $input  后台提交的配置（敏感键为明文或掩码）
     * @return array{config: array, changed: array} 加密后的配置与变更字段名（只记字段名不记值，§5.3）
     */
    public function buildConfigForSave(string $channel, array $input): array
    {
        $record = $this->find($channel);
        $raw = $record?->config ?? [];              // 库中原始值（敏感键为密文）
        $plain = $this->decryptedConfig($channel);   // 解密后的当前值，用于判断「是否真的变了」

        $saved = [];
        $changed = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $value = is_scalar($value) ? (string) $value : '';
            $isSensitive = in_array($key, PaymentChannel::SENSITIVE_KEYS, true);

            if ($isSensitive) {
                // 掩码 / 占位 / 空 → 未修改，保留库中原密文
                if ($value === '' || str_starts_with($value, '****') || $value === '__UNCHANGED__') {
                    if (isset($raw[$key])) {
                        $saved[$key] = $raw[$key];
                    }

                    continue;
                }

                $saved[$key] = Crypt::encryptString($value);
                if (($plain[$key] ?? null) !== $value) {
                    $changed[] = $key;
                }

                continue;
            }

            $saved[$key] = $value;
            if (($raw[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }

        // 未提交但库中已有的敏感键：保持原密文，避免被清空
        foreach (PaymentChannel::SENSITIVE_KEYS as $key) {
            if (! array_key_exists($key, $saved) && isset($raw[$key])) {
                $saved[$key] = $raw[$key];
            }
        }

        return ['config' => $saved, 'changed' => $changed];
    }
}
