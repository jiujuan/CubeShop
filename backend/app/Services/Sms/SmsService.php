<?php

namespace App\Services\Sms;

use App\Models\SmsConfig;
use App\Models\SmsLog;
use App\Support\Sms\Dto\SmsResult;
use App\Support\Sms\SmsChannel;
use App\Support\Sms\SmsChannelFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 短信发送门面（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D5
 *
 * 业务方（验证码、后台测试发送、二期通知 Job）只跟本类打交道：
 * 总开关 → 取启用渠道 → 工厂解析 → 发送 → **无论成败都落一条 sms_logs**。
 *
 * 三条对调用方的承诺：
 * 1. **永不抛异常**：渠道未落地、凭证缺失、HTTP 超时一律转成 `SmsResult::fail()`，
 *    调用方不写 try/catch 也能安全处理（验证码场景据此回退图形验证码）；
 * 2. **每次调用都有痕**：成功/失败/跳过都落 `sms_logs`，排查「用户说没收到」时
 *    第一手证据在这里，而不是去翻应用日志；
 * 3. **失败不影响主流程语义**：是否阻断由调用方决定（注册失败要报错，通知类可忽略）。
 */
class SmsService
{
    public function __construct(
        private readonly SmsSettings $settings,
        private readonly SmsLogService $logs,
    ) {
    }

    /**
     * 发送一条短信
     *
     * @param  string  $phone  国内手机号
     * @param  string  $templateCode  服务商模板 CODE
     * @param  array<string, string>  $params  模板变量（验证码场景为 ['code' => '123456']）
     * @param  string  $scene  业务场景（register / reset_password / test / general）
     */
    public function send(string $phone, string $templateCode, array $params = [], string $scene = 'general'): SmsResult
    {
        if (! $this->settings->enabled()) {
            $this->logs->record(
                configId: null,
                provider: 'none',
                phone: $phone,
                scene: $scene,
                templateCode: $templateCode,
                status: SmsLog::STATUS_SKIPPED,
                errorCode: 'sms_disabled',
                errorMsg: '短信总开关已关闭',
            );

            return SmsResult::fail('sms_disabled', '短信总开关已关闭');
        }

        $config = SmsConfig::query()->where('is_enabled', true)->first();

        if ($config === null) {
            $this->logs->record(
                configId: null,
                provider: 'none',
                phone: $phone,
                scene: $scene,
                templateCode: $templateCode,
                status: SmsLog::STATUS_SKIPPED,
                errorCode: 'no_channel',
                errorMsg: '未启用任何短信渠道',
            );

            return SmsResult::fail('no_channel', '未启用任何短信渠道');
        }

        try {
            $channel = SmsChannelFactory::make($config);
        } catch (Throwable $e) {
            // 工厂抛错只有两种：服务商未落地、生产环境凭证缺失。两者都必须留下可查的失败记录
            Log::error('sms.channel.unavailable', [
                'provider' => $config->provider,
                'scene' => $scene,
                'error' => $e->getMessage(),
            ]);

            $this->logs->record(
                configId: $config->id,
                provider: (string) $config->provider,
                phone: $phone,
                scene: $scene,
                templateCode: $templateCode,
                status: SmsLog::STATUS_FAILED,
                errorCode: 'channel_unavailable',
                errorMsg: $e->getMessage(),
            );

            return SmsResult::fail('channel_unavailable', $e->getMessage());
        }

        $result = $channel->send($phone, $templateCode, $params);

        $this->logs->record(
            configId: $config->id,
            provider: $channel->provider(),
            phone: $phone,
            scene: $scene,
            templateCode: $templateCode,
            status: $result->ok ? SmsLog::STATUS_SENT : SmsLog::STATUS_FAILED,
            errorCode: $result->errorCode,
            errorMsg: $result->errorMsg,
            bizId: $result->providerMessageId,
            latencyMs: $result->latencyMs,
        );

        return $result;
    }

    /**
     * 当前生效的渠道（后台展示「配置值 vs 实际生效」用）
     *
     * `degraded = true` 表示配的是阿里云但实际走的是 Mock（非生产环境凭证缺失时的兜底），
     * 后台据此提示「当前不会发出真实短信」，避免误以为联调已经打通。
     *
     * @return array{config: SmsConfig|null, channel: SmsChannel|null, provider: string|null, degraded: bool, error: string|null}
     */
    public function active(): array
    {
        $config = SmsConfig::query()->where('is_enabled', true)->first();

        if ($config === null) {
            return [
                'config' => null,
                'channel' => null,
                'provider' => null,
                'configured_provider' => null,
                'degraded' => false,
                'error' => null,
            ];
        }

        try {
            $channel = SmsChannelFactory::make($config);
        } catch (Throwable $e) {
            return [
                'config' => $config,
                'channel' => null,
                'provider' => null,
                'configured_provider' => $config->provider,
                'degraded' => true,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'config' => $config,
            'channel' => $channel,
            'provider' => $channel->provider(),
            'configured_provider' => $config->provider,
            'degraded' => $channel->provider() !== $config->provider,
            'error' => null,
        ];
    }
}
