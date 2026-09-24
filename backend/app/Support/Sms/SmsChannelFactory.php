<?php

namespace App\Support\Sms;

use App\Models\SmsConfig;
use App\Support\Sms\Adapters\AliyunSmsChannel;
use App\Support\Sms\Adapters\MockSmsChannel;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 短信渠道解析（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D3
 *
 * 性质与 `Support\Search\SearchEngineResolver` 相同：把「按配置选实现」收敛到一处，
 * 业务层（Services\Sms）只见 {@see SmsChannel} 接口，看不到任何具体适配器。
 *
 * 判定矩阵（**凭证完备度是唯一判据**）：
 *
 * | provider | 凭证 | 结果 |
 * |---|---|---|
 * | mock     | 任意 | MockSmsChannel |
 * | aliyun   | 齐备 | AliyunSmsChannel |
 * | aliyun   | 缺失 | 非生产 → Mock 兜底 + warning；**生产 → 抛错（fail-closed）** |
 * | tencent  | 任意 | 抛错（二期提供） |
 *
 * ⚠️ 生产环境凭证缺失**绝不**静默回退 Mock —— 那会造成「配置错了但前台报发送成功」，
 *    验证码永远收不到，而日志里一片成功，是本项目最想避免的一类静默事故（WMS SEC-01 同款语义）。
 */
final class SmsChannelFactory
{
    /**
     * @throws RuntimeException 服务商未落地，或生产环境凭证缺失
     */
    public static function make(SmsConfig $config): SmsChannel
    {
        return match ($config->provider) {
            SmsProvider::MOCK => new MockSmsChannel(),
            SmsProvider::ALIYUN => self::aliyun($config),
            SmsProvider::TENCENT => throw new RuntimeException('腾讯云短信适配器将在二期提供'),
            default => throw new RuntimeException('未知短信服务商：'.$config->provider),
        };
    }

    private static function aliyun(SmsConfig $config): SmsChannel
    {
        if ($config->credentialsComplete()) {
            return new AliyunSmsChannel(
                (string) $config->access_key_id,
                (string) $config->access_key_secret,
                (string) $config->sign_name,
            );
        }

        $missing = implode(', ', $config->missingCredentials());

        if (app()->environment('production')) {
            throw new RuntimeException('阿里云短信凭证未配置完整（缺失：'.$missing.'），生产环境拒绝回退 Mock 渠道');
        }

        Log::warning('sms.channel.fallback', [
            'provider' => SmsProvider::ALIYUN,
            'missing' => $missing,
            'hint' => '非生产环境回退 Mock 渠道，不会发出真实短信',
        ]);

        return new MockSmsChannel();
    }
}
