<?php

namespace App\Services\Sms;

use App\Support\Sms\SmsProvider;

/**
 * 短信验证码就绪度判定（第一期：AuthController 改造）
 *
 * 只回答一个问题：某个场景**现在**能不能走短信验证码。
 *
 * 存在的理由：开关、场景、模板、渠道是四个独立寿命的东西——
 * 管理员可能勾了「注册」却删了模板，也可能配好了渠道又临时关掉调试。
 * 若只看 `code_scenes` 就放行，用户点「发送验证码」会拿到一个发不出去的错误，
 * 而注册/登录是转化漏斗，任何硬失败都比回退图形验证码更糟。
 *
 * 因此判定结果分两类，调用方必须区别对待：
 * - **系统级不就绪**（开关关 / 场景未勾 / 模板缺失 / 无渠道 / 生产走 Mock）
 *   → {@see self::shouldFallbackToCaptcha()} 为 true，**静默回退图形验证码**，不打断用户；
 * - **用户级失败**（发送太频繁 / 今日超限 / 被锁定）由 `SmsCodeService` 单独返回，
 *   不属于本类职责，这类必须明确提示用户（否则他会一直点）。
 *
 * ⚠️ 本类只做判定，不落日志、不发短信；`SmsService::send()` 内部仍会再验一次总开关。
 */
final class SmsReadiness
{
    /** 系统级未就绪原因：调用方应回退图形验证码而非报错 */
    public const FALLBACK_REASONS = [
        'unknown_scene',
        'sms_disabled',
        'scene_not_enabled',
        'template_missing',
        'no_channel',
        'channel_unavailable',
        'mock_in_production',
    ];

    public function __construct(
        private readonly SmsSettings $settings,
        private readonly SmsService $sms,
    ) {
    }

    /**
     * 判定某场景是否可用短信验证码
     *
     * @return array{ready: bool, reason: string|null, provider: string|null}
     */
    public function check(string $scene): array
    {
        if (! $this->settings->isKnownScene($scene)) {
            return $this->no('unknown_scene');
        }

        if (! $this->settings->enabled()) {
            return $this->no('sms_disabled');
        }

        if (! $this->settings->codeSceneEnabled($scene)) {
            return $this->no('scene_not_enabled');
        }

        // 模板 CODE 是阿里云账号级别的，没配就是发不出去 —— 早判定早回退
        if ($this->settings->templateFor($scene) === null) {
            return $this->no('template_missing');
        }

        $active = $this->sms->active();

        if ($active['config'] === null) {
            return $this->no('no_channel');
        }

        if ($active['error'] !== null) {
            return $this->no('channel_unavailable');
        }

        $provider = $active['provider'];

        // Mock 在非生产是开发便利（验证码写进日志），生产环境则必须拦死：
        // 否则「配了渠道但凭证没填」会变成所有人收不到码的静默事故
        if (app()->isProduction() && $provider === SmsProvider::MOCK) {
            return $this->no('mock_in_production', $provider);
        }

        return [
            'ready' => true,
            'reason' => null,
            'provider' => $provider,
        ];
    }

    /**
     * 该原因是否应当回退图形验证码
     *
     * 系统级不就绪一律回退；未知原因（null / 非本类产出）不回退，
     * 避免把真正的用户级错误（频繁、超限）误判成「切回图形」而掩盖问题。
     */
    public function shouldFallbackToCaptcha(?string $reason): bool
    {
        return $reason !== null && in_array($reason, self::FALLBACK_REASONS, true);
    }

    /** @return array{ready: bool, reason: string|null, provider: string|null} */
    private function no(string $reason, ?string $provider = null): array
    {
        return [
            'ready' => false,
            'reason' => $reason,
            'provider' => $provider,
        ];
    }
}
