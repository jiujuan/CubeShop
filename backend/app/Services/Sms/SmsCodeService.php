<?php

namespace App\Services\Sms;

use App\Support\Sms\Dto\SmsResult;
use Illuminate\Support\Facades\Cache;

/**
 * 手机验证码用例（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D6
 *
 * 验证码是业务用例，不是协议能力，因此放在 `Services`（D0 判定规则第 4 问）：
 * 它管的是「谁能拿码、拿几次、错了几次」这类业务状态，而不是怎么跟阿里云通信。
 *
 * 四道闸门（缺一个都会被刷）：
 * 1. 同手机号 **60 秒** 才能再发一条（`sms:last:{phone}`）；
 * 2. 同手机号 **24 小时 10 条**（`sms:day:{phone}`，滑动 24h 窗口）；
 * 3. 同 IP 每分钟次数 —— 走路由层 `throttle:sms-send`（RateLimiter 'sms-send'），不在此处重复实现；
 * 4. 校验连续错 **5 次** 锁定 **15 分钟**，并立即作废验证码（防止锁定期间继续撞库）。
 *
 * ⚠️ 验证码只存在于缓存里：不落库、不落日志（`sms_logs` 连模板参数都不存，
 *    因为验证码场景的参数就是验证码明文）。
 */
class SmsCodeService
{
    public const LENGTH = 6;

    /** 验证码有效期（分钟） */
    public const TTL_MINUTES = 5;

    /** 连续校验失败上限 */
    public const MAX_ATTEMPTS = 5;

    /** 触发上限后的锁定时长（分钟） */
    public const LOCK_MINUTES = 15;

    /** 单手机号 24 小时发送上限 */
    public const DAILY_LIMIT = 10;

    /** 单手机号发送间隔（秒） */
    public const RESEND_INTERVAL = 60;

    public function __construct(
        private readonly SmsSettings $settings,
        private readonly SmsService $sms,
    ) {
    }

    /**
     * 发送验证码
     *
     * @param  string  $scene  register / reset_password（须在 {@see SmsSettings::CODE_SCENES} 内）
     */
    public function send(string $scene, string $phone): SmsResult
    {
        // 总开关是全局闸门：关闭时不必再检查模板/频率，也不产生任何验证码
        if (! $this->settings->enabled()) {
            return SmsResult::fail('sms_disabled', '短信总开关已关闭，请改用图形验证码');
        }

        if ($this->isLocked($scene, $phone)) {
            return SmsResult::fail('locked', '尝试次数过多，请稍后再试');
        }

        $cooldown = $this->sendCooldown($phone);

        if ($cooldown > 0) {
            return SmsResult::fail('rate_limited', '发送过于频繁，请 '.$cooldown.' 秒后再试');
        }

        if ($this->dailyCount($phone) >= self::DAILY_LIMIT) {
            return SmsResult::fail('daily_limit', '今日发送次数已达上限，请明天再试');
        }

        $template = $this->settings->templateFor($scene);

        if ($template === null) {
            // 模板 CODE 是阿里云账号级别的，没配就是发不出去 —— 早失败好过静默丢一条
            return SmsResult::fail('template_missing', '短信模板未配置，请联系管理员');
        }

        $code = $this->generateCode();

        Cache::put($this->codeKey($scene, $phone), $code, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($this->attemptKey($scene, $phone));

        $result = $this->sms->send($phone, $template, ['code' => $code], $scene);

        // 只在真正发出去后才计频：因我方配置错误导致的失败不该消耗用户的重试机会
        if ($result->ok) {
            Cache::put($this->lastKey($phone), now()->timestamp, now()->addSeconds(self::RESEND_INTERVAL));
            Cache::add($this->dayKey($phone), 0, now()->addHours(24));
            Cache::increment($this->dayKey($phone));
        } else {
            Cache::forget($this->codeKey($scene, $phone));
        }

        return $result;
    }

    /**
     * 校验验证码（成功即销毁，一次性）
     */
    public function verify(string $scene, string $phone, string $code): bool
    {
        if ($this->isLocked($scene, $phone)) {
            return false;
        }

        $stored = Cache::get($this->codeKey($scene, $phone));

        if (! is_string($stored)) {
            return false;
        }

        if (! hash_equals($stored, trim($code))) {
            $this->registerFailure($scene, $phone);

            return false;
        }

        Cache::forget($this->codeKey($scene, $phone));
        Cache::forget($this->attemptKey($scene, $phone));

        return true;
    }

    /** 是否被锁定（连续校验失败达到上限） */
    public function isLocked(string $scene, string $phone): bool
    {
        return Cache::has($this->lockKey($scene, $phone));
    }

    /** 距下次可发送的剩余秒数（0 = 立即可发） */
    public function sendCooldown(string $phone): int
    {
        $sentAt = Cache::get($this->lastKey($phone));

        if (! is_numeric($sentAt)) {
            return 0;
        }

        $passed = now()->timestamp - (int) $sentAt;

        return max(0, self::RESEND_INTERVAL - $passed);
    }

    /** 24 小时窗口内已发送条数 */
    public function dailyCount(string $phone): int
    {
        return (int) Cache::get($this->dayKey($phone), 0);
    }

    /** 剩余可校验次数（已被锁定返回 0） */
    public function remainingAttempts(string $scene, string $phone): int
    {
        if ($this->isLocked($scene, $phone)) {
            return 0;
        }

        return max(0, self::MAX_ATTEMPTS - (int) Cache::get($this->attemptKey($scene, $phone), 0));
    }

    private function registerFailure(string $scene, string $phone): void
    {
        $key = $this->attemptKey($scene, $phone);

        Cache::add($key, 0, now()->addMinutes(self::TTL_MINUTES));
        $attempts = Cache::increment($key);

        if ($attempts >= self::MAX_ATTEMPTS) {
            // 锁定同时作废验证码：否则用户可以在锁定期内继续拿旧码撞
            Cache::put($this->lockKey($scene, $phone), now()->timestamp, now()->addMinutes(self::LOCK_MINUTES));
            Cache::forget($this->codeKey($scene, $phone));
        }
    }

    private function generateCode(): string
    {
        $max = 10 ** self::LENGTH - 1;

        return str_pad((string) random_int(0, $max), self::LENGTH, '0', STR_PAD_LEFT);
    }

    // ---------------- 缓存键 ----------------

    private function codeKey(string $scene, string $phone): string
    {
        return sprintf('sms_code:%s:%s', $scene, $phone);
    }

    private function attemptKey(string $scene, string $phone): string
    {
        return sprintf('sms_code_attempts:%s:%s', $scene, $phone);
    }

    private function lockKey(string $scene, string $phone): string
    {
        return sprintf('sms_code_lock:%s:%s', $scene, $phone);
    }

    private function lastKey(string $phone): string
    {
        return sprintf('sms_code_last:%s', $phone);
    }

    private function dayKey(string $phone): string
    {
        return sprintf('sms_code_day:%s', $phone);
    }
}
