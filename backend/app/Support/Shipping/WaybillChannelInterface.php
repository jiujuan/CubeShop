<?php

namespace App\Support\Shipping;

/**
 * 电子面单申请渠道适配器（出单侧，与轨迹侧 ShippingChannelInterface 平行）
 *
 * 实现约定（对齐轨迹侧）：
 * - 实现类不得抛异常以外的副作用，失败返回 WaybillResult::fail()；
 * - 密钥从 config('services.waybill.*') 读取，禁止硬编码；
 * - 新渠道（顺丰/菜鸟等）实现本接口并在 AppServiceProvider 注册即可切换。
 */
interface WaybillChannelInterface
{
    /**
     * 申请电子面单
     *
     * @param  WaybillRequest  $request  收寄件人、重量、品名、内部单号
     */
    public function issue(WaybillRequest $request): WaybillResult;

    /** 渠道是否可用（未配置密钥时返回 false，调用方回落手动录入） */
    public function available(): bool;

    /** 渠道标识（mock/kuaidi100…），落 shippings.waybill_channel 便于审计与重打 */
    public function channelName(): string;
}
