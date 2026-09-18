<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 统一 API 响应封装
 *
 * 响应结构（见架构文档 8.1）：
 * {
 *   "code": 0,          // 0=成功，非 0=业务/系统错误码
 *   "message": "ok",
 *   "data": { ... }
 * }
 *
 * SEC-04：分页响应默认不再暴露精确 `total` / `total_pages`，仅返回 `has_more`。
 * 未登录一个 GET 即可拿到「在售商品精确总数」「评价总数」等经营指标，属于低成本情报泄露。
 * 放行范围见 {@see OWN_RESOURCE_PATTERNS} 与 {@see shouldExposeTotal()}。
 */
trait ApiResponse
{
    /**
     * 允许保留精确总量的「本人资源」路径白名单
     *
     * 判定原则：数据集合的规模只与**调用者本人**有关（我的订单/地址/收藏/余额流水/工单/消息），
     * 不泄露平台经营指标，因此保留 total 不影响安全目标，也避免无谓的前端体验退化。
     *
     * 平台公共资源（商品、评价、领券中心等）**不在**白名单内——即便调用者已登录。
     */
    private const OWN_RESOURCE_PATTERNS = [
        'api/orders',
        'api/orders/*',
        'api/user/*',   // 我的地址 /user/addresses、我的余额 /user/balance*
        'api/me/*',     // 我的评价 /me/reviews、我的消息 /me/notifications、我的收藏 /me/favorites
        'api/cs/*',     // 用户端客服：我的工单、FAQ
    ];
    /**
     * 成功响应
     */
    protected function success(mixed $data = null, string $message = 'ok', int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }

    /**
     * 业务失败响应
     */
    protected function fail(string $message = 'fail', int $code = 1, mixed $data = null, int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }

    /**
     * 分页数据响应（Laravel Paginator）
     *
     * @param  mixed  $paginator  LengthAwarePaginator
     * @param  string  $message
     * @param  bool|null  $exposeTotal  显式指定是否暴露精确总量；null = 按 {@see shouldExposeTotal()} 自动判定
     */
    protected function paginated(mixed $paginator, string $message = 'ok', ?bool $exposeTotal = null): JsonResponse
    {
        $exposeTotal = $exposeTotal ?? $this->shouldExposeTotal();

        return $this->success([
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                // SEC-04：未放行时置 null（而非 0），避免"0 条"被误读为真实结果
                'total' => $exposeTotal ? $paginator->total() : null,
                'total_pages' => $exposeTotal ? $paginator->lastPage() : null,
                'has_more' => $paginator->hasMorePages(),
            ],
        ], $message);
    }

    /**
     * 是否暴露精确总量（SEC-04）
     *
     * 放行条件（任一满足）：
     * 1. 后台接口：路由指向 Admin 命名空间，或路径为 api/admin/*，或持有 report.view 权限；
     * 2. 本人资源：路径命中 {@see OWN_RESOURCE_PATTERNS}。
     *
     * 非 HTTP 上下文（如单元测试直接调用）默认放行，保持向后兼容。
     */
    protected function shouldExposeTotal(): bool
    {
        $request = request();

        if (! $request instanceof Request) {
            return true;
        }

        $action = $request->route()?->getActionName() ?? '';
        if (str_contains($action, 'App\\Http\\Controllers\\Admin\\')) {
            return true;
        }

        if ($request->is('api/admin/*')) {
            return true;
        }

        // 报表权限兜底：买家模型未接入 spatie（无 hasPermissionTo），必须先做能力探测
        $user = $request->user();
        if ($user !== null && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('report.view')) {
            return true;
        }

        foreach (self::OWN_RESOURCE_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
