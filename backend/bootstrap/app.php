<?php

use App\Exceptions\BusinessException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 纯 API 后端：未认证不跳转 /login（默认 redirectGuestsTo 会调用 route('login') 导致 500）
        $middleware->redirectGuestsTo(null);

        // spatie 权限中间件别名：permission:product.create / role:super_admin
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // 账号状态校验（V1.1 T-022）：禁用后 Token 立即失效
            'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            // 反枚举频控（SEC-10）：对敏感前缀的 404 做计数与限流
            'enum.guard' => \App\Http\Middleware\EnumGuard::class,
        ]);

        // SEC-10：反枚举频控中间件作用于全部 API 路由，
        // 中间件内部按前缀过滤，仅对公开资源（商品/工单/评价/订单）的 404 计数与限流。
        $middleware->api(append: [\App\Http\Middleware\EnumGuard::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 请求一律返回 JSON
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 统一异常响应结构：{ code, message, data }
        // 错误码对齐 API 文档 1.3：40000/40001/40003/40004/40009/50000
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // 非 API 请求走默认渲染
            }

            // 业务异常：业务码直接透出，HTTP 状态按业务码映射
            if ($e instanceof BusinessException) {
                return response()->json([
                    'code' => $e->businessCode,
                    'message' => $e->getMessage(),
                    'data' => null,
                ], match ($e->businessCode) {
                    40001 => 401,
                    40003 => 403,
                    40004 => 404,
                    40009 => 409,
                    40029 => 429,
                    default => 400,
                });
            }

            // 表单验证失败：40000 / HTTP 422
            if ($e instanceof ValidationException) {
                return response()->json([
                    'code' => 40000,
                    'message' => '参数校验失败',
                    'data' => ['errors' => $e->errors()],
                ], 422);
            }

            // 未认证：40001 / HTTP 401
            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'code' => 40001,
                    'message' => '未登录或登录已过期',
                    'data' => null,
                ], 401);
            }

            // spatie 无权限：40003 / HTTP 403
            if ($e instanceof UnauthorizedException) {
                return response()->json([
                    'code' => 40003,
                    'message' => '无权限执行此操作',
                    'data' => null,
                ], 403);
            }

            // 资源不存在：40004 / HTTP 404
            if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
                return response()->json([
                    'code' => 40004,
                    'message' => '资源不存在',
                    'data' => null,
                ], 404);
            }

            // 限流：40009 / HTTP 429
            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'code' => 40009,
                    'message' => '请求过于频繁，请稍后再试',
                    'data' => null,
                ], 429);
            }

            // 其他 HTTP 异常：HTTP 状态与业务码对齐（429 限流映射业务码 40009）
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return response()->json([
                    'code' => $status === 429 ? 40009 : $status * 100,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : '请求失败',
                    'data' => null,
                ], $status);
            }

            // 系统异常：50000 / HTTP 500，不向前端暴露内部细节
            report($e);

            return response()->json([
                'code' => 50000,
                'message' => config('app.debug') ? $e->getMessage() : '服务器内部错误',
                'data' => null,
            ], 500);
        });
    })->create();
