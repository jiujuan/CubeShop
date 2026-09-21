<?php

namespace App\Support\Shipping;

/**
 * 物流轨迹查询渠道适配器（V1.1 T-045，E03）
 *
 * 实现约定：
 * - 实现类不得抛异常以外的副作用；失败请返回 TraceResult::fail()；
 * - 渠道密钥等配置从 config('services.shipping.*') 读取，禁止硬编码；
 * - 新渠道（快递100/快递鸟等）实现本接口并在 AppServiceProvider 注册即可切换。
 */
interface ShippingChannelInterface
{
    /**
     * 查询运单轨迹（每次调用代表一次第三方请求，实现方自行处理限流与超时）
     *
     * @param  string  $companyCode  系统内部快递公司编码（SF/ZTO…），渠道自行转换
     * @param  string  $trackingNo  运单号
     * @param  string|null  $phone  收/寄件人手机号；顺丰、中通等渠道强制必填
     */
    public function query(string $companyCode, string $trackingNo, ?string $phone = null): TraceResult;

    /** 渠道是否可用（未配置密钥时返回 false，调用方跳过拉取） */
    public function available(): bool;
}
