<?php

namespace App\Http\Middleware;

use App\Models\AuthLog;
use App\Services\Auth\AuthLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 认证尝试兜底日志（传输层）
 *
 * 控制器已在各分支精确记录成功与业务失败（验证码错误 / 账号锁定 / 密码错 / 注销等，
 * 并置 `auth_log_skip` 标记避免本中间件重复记录）。
 * 本中间件补齐**控制器未显式覆盖**的失败：
 * - 429：传输层限流（throttle:auth / throttle:login / throttle:5,1），含同 IP 注册上限；
 * - 500：未预期的服务器异常。
 *
 * 仅作用于登录 / 注册两个公开入口（其余路径直接放行）。捕获后**原样 re-throw**，
 * 由全局异常处理器渲染统一 JSON，不影响既有错误响应。
 */
class LogAuthAttempt
{
    public function __construct(private readonly AuthLogService $authLog) {}

    public function handle(Request $request, Closure $next): Response
    {
        $event = $this->eventOf($request);
        if ($event === null) {
            return $next($request);
        }

        $identifier = (string) $request->input('username') ?: (string) $request->input('target');

        try {
            return $next($request);
        } catch (Throwable $e) {
            // 控制器已显式记录的失败（含 422 参数校验）不再重复
            if ($request->attributes->get('auth_log_skip') || $e instanceof ValidationException) {
                throw $e;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($status === 422) {
                throw $e;
            }

            $failReason = match (true) {
                $status === 429 => 'too_many_requests',
                $status >= 500 => 'server_error',
                default => 'rejected',
            };

            $this->authLog->record($event, false, [
                'identifier' => $identifier !== '' ? $identifier : null,
                'fail_reason' => $failReason,
                'detail' => $status >= 500 ? ['error' => mb_substr($e->getMessage(), 0, 255)] : null,
            ]);

            throw $e;
        }
    }

    /** 仅对登录/注册记录；返回事件名，非认证入口返回 null */
    private function eventOf(Request $request): ?string
    {
        $path = $request->path(); // 形如 api/auth/login

        if (str_ends_with($path, 'auth/login')) {
            return AuthLog::EVENT_LOGIN;
        }
        if (str_ends_with($path, 'auth/register')) {
            return AuthLog::EVENT_REGISTER;
        }

        return null;
    }
}
