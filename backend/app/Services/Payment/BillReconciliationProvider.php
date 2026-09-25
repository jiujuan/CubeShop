<?php

namespace App\Services\Payment;

use App\Services\Payment\Dto\StatementResult;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use Throwable;

/**
 * 账单提供方：通过渠道网关拉取某日交易流水（A7-支付渠道对账）
 *
 * 封装「拉账单可能失败」的细节，对外只暴露一个安全的 provideResult()。
 * 失败（网络/鉴权/解析/渠道不支持）统一降级为 StatementResult::fail，
 * 由引擎改走日志源，绝不中断整轮对账。
 */
class BillReconciliationProvider
{
    public function __construct(
        private readonly PaymentGatewayFactory $factory,
        private readonly PaymentChannelService $channels,
    ) {}

    public function provideResult(string $channel, string $date): StatementResult
    {
        try {
            $gateway = $this->factory->make($channel);

            return $gateway->downloadBill($date, $this->channels->decryptedConfig($channel));
        } catch (Throwable $e) {
            return StatementResult::fail('账单拉取异常：'.$e->getMessage());
        }
    }
}
