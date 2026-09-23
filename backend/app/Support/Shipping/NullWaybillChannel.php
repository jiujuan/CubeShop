<?php

namespace App\Support\Shipping;

/**
 * 空出单渠道：WAYBILL_CHANNEL 留空 / off 时启用（降级）。
 *
 * available()=false，WaybillService / OrderService 据此回落手动录入，保证未配置出单账号时
 * 发货链路与现有「后台填单号」行为完全一致。
 */
class NullWaybillChannel implements WaybillChannelInterface
{
    public function issue(WaybillRequest $request): WaybillResult
    {
        return WaybillResult::fail('未配置电子面单渠道（WAYBILL_CHANNEL），无法自动出单');
    }

    public function available(): bool
    {
        return false;
    }

    public function channelName(): string
    {
        return 'null';
    }
}
