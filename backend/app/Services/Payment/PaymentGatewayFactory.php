<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Gateways\AlipayGateway;
use App\Services\Payment\Gateways\BalanceGateway;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\OfflineGateway;
use App\Services\Payment\Gateways\WechatGateway;

/**
 * 网关工厂（收银台方案 §3.2）
 *
 * 解析规则：
 * - wechat / alipay：沙箱模式或未配置商户参数 → MockGateway 代理；否则真实网关（P3）
 * - balance / offline / mock：各自网关
 * 生产环境强制禁止 mock 渠道对前台开放（控制器层另有一道硬校验）。
 */
class PaymentGatewayFactory
{
    public function __construct(
        private readonly PaymentChannelService $channels,
        private readonly BalanceService $balances,
    ) {
    }

    public function make(string $channel): PaymentGateway
    {
        return match ($channel) {
            PaymentChannel::CHANNEL_BALANCE => new BalanceGateway($this->balances),
            PaymentChannel::CHANNEL_OFFLINE => new OfflineGateway($this->channels),
            PaymentChannel::CHANNEL_MOCK => new MockGateway(Payment::CHANNEL_MOCK),
            PaymentChannel::CHANNEL_WECHAT, PaymentChannel::CHANNEL_ALIPAY => $this->makeOnline($channel),
            default => throw BusinessException::badRequest('不支持的支付渠道'),
        };
    }

    /**
     * 在线渠道解析（§8.1）
     *
     * - 微信：V3 无官方沙箱 → 沙箱模式或配置不全时由 Mock 代理，配置齐全才走真实网关
     * - 支付宝：配置齐全即走真实网关，sandbox=true 时指向支付宝官方沙箱网关（L2）
     * - 其余情况降级 Mock，保证前台不 500
     */
    private function makeOnline(string $channel): PaymentGateway
    {
        $config = $this->channels->decryptedConfig($channel);

        if ($channel === PaymentChannel::CHANNEL_WECHAT) {
            return (! $this->channels->isSandbox($channel) && $this->configComplete($channel, $config))
                ? new WechatGateway()
                : new MockGateway($channel);
        }

        // alipay
        return $this->configComplete($channel, $config)
            ? new AlipayGateway()
            : new MockGateway($channel);
    }

    /** 商户参数是否齐全（P3 真实网关的前置条件） */
    private function configComplete(string $channel, array $config): bool
    {
        $required = $channel === PaymentChannel::CHANNEL_WECHAT
            ? ['app_id', 'mch_id', 'api_v3_key', 'merchant_private_key', 'merchant_cert_serial_no']
            : ['app_id', 'private_key', 'alipay_public_key'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }
}
