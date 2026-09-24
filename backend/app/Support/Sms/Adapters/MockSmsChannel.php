<?php

namespace App\Support\Sms\Adapters;

use App\Support\Sms\Dto\SmsResult;
use App\Support\Sms\SmsChannel;
use App\Support\Sms\SmsProvider;
use Illuminate\Support\Str;

/**
 * Mock 短信渠道（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D3
 *
 * 不发真短信、不请求任何外部服务，只返回一个成功的 {@see SmsResult}：
 * - 开发/测试环境下凭证缺失时的兜底渠道，让注册、重置密码等链路可以完整跑通；
 * - 后台「测试发送」也能用它验证前端到日志的整条链路。
 *
 * ⚠️ 生产环境**不会**静默退化到这里 —— 凭证缺失时 `SmsChannelFactory` 直接抛错（fail-closed），
 *    否则「配置错了但看起来发送成功」是最难排查的一类事故。
 */
final class MockSmsChannel implements SmsChannel
{
    public function send(string $phone, string $templateCode, array $params): SmsResult
    {
        return SmsResult::ok(
            providerMessageId: 'mock-'.Str::lower(Str::uuid()->toString()),
            raw: ['mock' => true, 'template_code' => $templateCode],
            latencyMs: 0,
        );
    }

    public function available(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return SmsProvider::MOCK;
    }
}
