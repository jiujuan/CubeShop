<?php

namespace App\Http\Middleware;

use App\Models\SysUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 账号状态校验（V1.1 F04 / T-022）
 *
 * 被禁用的账号即使持有未过期 Token 也立即失效：
 * - 清空该账号全部 Token（避免下次仍能通过）；
 * - 抛出未认证异常（HTTP 401 / 业务码 40001），前端据此清理登录态。
 *
 * 挂在 `auth:sanctum` 之后，确保 $request->user() 已解析。
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof SysUser && (int) $user->status !== 1) {
            $user->tokens()->delete();

            throw new AuthenticationException('账号已被禁用，请联系管理员');
        }

        return $next($request);
    }
}
