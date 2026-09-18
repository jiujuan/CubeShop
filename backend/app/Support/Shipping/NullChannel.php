<?php

namespace App\Support\Shipping;

/**
 * 空渠道（V1.1 T-045 降级）：未配置渠道密钥时使用。
 *
 * available()=false，TracePullService / Command 据此跳过拉取，
 * 保证本地开发与测试环境（无渠道账号）发货链路与轨迹展示正常可用。
 */
class NullChannel implements ShippingChannelInterface
{
    public function query(string $companyCode, string $trackingNo): TraceResult
    {
        return TraceResult::fail('未配置物流轨迹查询渠道（SHIPPING_CHANNEL），无法查询');
    }

    public function available(): bool
    {
        return false;
    }
}
