<?php

namespace App\Services\Auth;

use App\Models\AuthLog;
use Illuminate\Support\Facades\Request;

/**
 * 认证日志服务（登录 / 注册 / 登出）
 *
 * 设计要点：
 * - 与 {@see \App\Services\Common\OperationLogService} 同口径：写失败不影响主业务（try/catch + report）。
 * - IP / UA 从 Request facade 取，调用方无需关心。
 * - `fail_reason` 记录**内部明细**（如「验证码错误」「账号锁定」「密码错误」「账号已注销」），
 *   但响应层仍按安全约束（SEC-08）统一文案，不在前端暴露差异。
 *
 * 调用约定：在 AuthController 各分支精确记录，并辅以 `LogAuthAttempt` 传输层兜底
 * （捕获 429 限流 / 意外 500 等控制器未显式覆盖的失败）。
 */
class AuthLogService
{
    /**
     * 记录一条认证日志。
     *
     * @param  string  $event  AuthLog::EVENT_* 常量
     * @param  bool  $success  是否成功
     * @param  array<string, mixed>  $context 支持键：
     *                                         user_id, actor_type, identifier,
     *                                         fail_reason, device_id, token_id, detail
     */
    public function record(string $event, bool $success, array $context = []): ?AuthLog
    {
        try {
            return AuthLog::create([
                'user_id' => $context['user_id'] ?? null,
                'actor_type' => $context['actor_type'] ?? AuthLog::ACTOR_CUSTOMER,
                'identifier' => $context['identifier'] ?? null,
                'event' => $event,
                'success' => $success,
                'fail_reason' => $context['fail_reason'] ?? null,
                'ip' => Request::ip(),
                'user_agent' => mb_substr((string) Request::userAgent(), 0, 512),
                'device_id' => $context['device_id'] ?? null,
                'token_id' => $context['token_id'] ?? null,
                // detail 交由模型 `array` 强转序列化，这里传原始数组/null 即可
                'detail' => $context['detail'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主业务
            report($e);

            return null;
        }
    }
}
