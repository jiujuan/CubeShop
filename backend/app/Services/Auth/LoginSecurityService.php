<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;

/**
 * 登录与注册安全（SEC-07 账号锁定 / SEC-08 账号枚举与批量注册）
 *
 * 传输层限流（`throttle:login`，5 次/分）只能挡住**高速**撞库：
 * 攻击者把节奏压到 4 次/分即可无限续，且可按 IP 轮换代理池绕过。
 * 因此这里补**账号维度**的失败计数：同一账号在滑动窗口内失败达阈值即锁定一段时间，
 * 与 IP 无关——即使攻击者有 1 万个 IP，也无法绕过"这个账号被锁了"。
 *
 * 载体选用 Cache 而非数据库，原因有三：
 * 1. 计数是高频写、低价值数据，落库会放大写压力并产生大量垃圾行；
 * 2. 锁定状态天然可过期，无需清理任务；
 * 3. 不引入新表，免迁移。
 *
 * 代价：默认 file/database 缓存驱动下，多机部署时计数不共享。
 * 生产请配置 redis/memcached 作为 CACHE_STORE，否则锁定的实际阈值会随节点数放大。
 */
final class LoginSecurityService
{
    /** 滑动窗口内允许的失败次数 */
    public const MAX_ATTEMPTS = 10;

    /** 失败计数窗口（分钟） */
    public const WINDOW_MINUTES = 15;

    /** 触发阈值后的锁定时长（分钟） */
    public const LOCK_MINUTES = 30;

    /** 同一 IP 每日注册上限 */
    public const REGISTER_DAILY_PER_IP = 20;

    /**
     * 断言账号未被锁定；已锁定则抛出 429
     */
    public function assertNotLocked(string $account): void
    {
        $ttl = $this->lockedSeconds($account);
        if ($ttl <= 0) {
            return;
        }

        // 统一文案（SEC-08）：不区分"账号不存在"与"密码错误"，也不提示剩余时间细节，
        // 但**锁定状态本身必须可感知**，否则正常用户会以为是密码错而反复重试。
        throw BusinessException::tooManyRequests(
            '尝试次数过多，账号已锁定，请'.(int) ceil($ttl / 60).'分钟后重试'
        );
    }

    /**
     * 剩余锁定秒数（0 表示未锁定）
     */
    public function lockedSeconds(string $account): int
    {
        $until = Cache::get($this->lockKey($account));

        return is_numeric($until) ? max(0, (int) $until - time()) : 0;
    }

    /**
     * 记录一次失败；达到阈值则锁定。返回本次失败后的剩余可尝试次数。
     */
    public function recordFailure(string $account): int
    {
        $key = $this->failKey($account);

        // 用 Cache::add 原子初始化，避免并发下计数被覆盖
        Cache::add($key, 0, now()->addMinutes(self::WINDOW_MINUTES));
        $attempts = (int) Cache::increment($key);

        // 自增后刷新窗口过期时间，保证"持续尝试"不会因窗口滑过而清零
        Cache::put($key, $attempts, now()->addMinutes(self::WINDOW_MINUTES));

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put($this->lockKey($account), time() + self::LOCK_MINUTES * 60, now()->addMinutes(self::LOCK_MINUTES));
            Cache::forget($key);
        }

        return max(0, self::MAX_ATTEMPTS - $attempts);
    }

    /** 登录成功：清空失败计数与锁定 */
    public function clear(string $account): void
    {
        Cache::forget($this->failKey($account));
        Cache::forget($this->lockKey($account));
    }

    /**
     * 注册频控：同 IP 每日上限（SEC-08，防批量注册薅券）
     */
    public function assertRegisterAllowed(string $ip): void
    {
        $key = 'auth:register:ip:'.md5($ip);

        // 原子初始化 + 自增，避免并发下重复初始化导致计数被重置
        Cache::add($key, 0, now()->addDay());
        $count = (int) Cache::increment($key);

        if ($count > self::REGISTER_DAILY_PER_IP) {
            throw BusinessException::tooManyRequests('今日注册次数已达上限，请明日再试');
        }
    }

    /**
     * 账号维度缓存键
     *
     * 账号标识可能含任意字符（邮箱、手机号、用户名），直接拼接会污染缓存键命名空间，
     * 因此统一取 HMAC 摘要作为键名。
     */
    private function failKey(string $account): string
    {
        return 'auth:login:fail:'.$this->hash($account);
    }

    private function lockKey(string $account): string
    {
        return 'auth:login:lock:'.$this->hash($account);
    }

    private function hash(string $account): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($account)), (string) config('app.key'));
    }
}
