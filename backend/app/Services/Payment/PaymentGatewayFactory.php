<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Gateways\BalanceGateway;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\OfflineGateway;

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
     * 在线渠道：P3 前（或沙箱模式 / 配置不全）一律由 Mock 网关代理
     */
    private function makeOnline(string $channel): PaymentGateway
    {
        if ($this->channels->isSandbox($channel)) {
            return new MockGateway($channel);
        }

        $config = $this->channels->decryptedConfig($channel);

        if (class_exists(\App\Services\Payment\Gateways\WechatGateway::class) && $this->configComplete($channel, $config)) {
            return $channel === PaymentChannel::CHANNEL_WECHAT
                ? new \App\Services\Payment\Gateways\WechatGateway()
                : new \App\Services\Payment\Gateways\AlipayGateway();
        }

        // 正式模式但配置不全 → 降级 Mock，避免前台直接 500
        return new MockGateway($channel);
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
