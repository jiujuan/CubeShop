<?php

namespace App\Support\Sms;

/**
 * 短信渠道契约（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D2
 *
 * 对齐 `Support\Shipping\ShippingChannelInterface` 的极简风格：只暴露「能不能用」与「发一条」。
 * 二期加腾讯云时，业务层（Services\Sms）完全不需要改动。
 *
 * ⚠️ 适配器**不得让异常穿透**：HTTP 超时、JSON 解析失败、凭证缺失一律转成
 * {@see \App\Support\Sms\Dto\SmsResult::fail()}，由调用方决定是重试、降级还是记日志。
 */
interface SmsChannel
{
    /**
     * 发送单条短信
     *
     * @param  string  $phone  国内手机号（11 位；批量发送非本期目标）
     * @param  string  $templateCode  服务商模板标识（阿里云 `SMS_xxx`；腾讯云二期映射 TemplateId）
     * @param  array<string, string>  $params  模板变量，如 ['code' => '123456']
     */
    public function send(string $phone, string $templateCode, array $params): Dto\SmsResult;

    /** 渠道是否可用（凭证齐备且未被禁用；调用方据此跳过或降级） */
    public function available(): bool;

    /** 服务商标识（落日志用，见 {@see \App\Support\Sms\SmsProvider}） */
    public function provider(): string;
}
