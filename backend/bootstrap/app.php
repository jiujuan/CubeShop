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
        ]);
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

            // 业务异常：业务码直接透出
            if ($e instanceof BusinessException) {
                return response()->json([
                    'code' => $e->businessCode,
                    'message' => $e->getMessage(),
                    'data' => null,
                ]);
            }

            // 表单验证失败：40000
            if ($e instanceof ValidationException) {
                return response()->json([
                    'code' => 40000,
                    'message' => '参数校验失败',
                    'data' => ['errors' => $e->errors()],
                ]);
            }

            // 未认证：40001
            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'code' => 40001,
                    'message' => '未登录或登录已过期',
                    'data' => null,
                ]);
            }

            // spatie 无权限：40003
            if ($e instanceof UnauthorizedException) {
                return response()->json([
                    'code' => 40003,
                    'message' => '无权限执行此操作',
                    'data' => null,
                ]);
            }

            // 资源不存在：40004
            if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
                return response()->json([
                    'code' => 40004,
                    'message' => '资源不存在',
                    'data' => null,
                ]);
            }

            // 限流
            if ($e instanceof ThrottleRequestsException) {
                return response()->json([
                    'code' => 40009,
                    'message' => '请求过于频繁，请稍后再试',
                    'data' => null,
                ]);
            }

            // 其他 HTTP 异常
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return response()->json([
                    'code' => $status * 100, // 保持与业务码分段不冲突
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : '请求失败',
                    'data' => null,
                ]);
            }

            // 系统异常：50000，不向前端暴露内部细节
            report($e);

            return response()->json([
                'code' => 50000,
                'message' => config('app.debug') ? $e->getMessage() : '服务器内部错误',
                'data' => null,
            ]);
        });
    })->create();
