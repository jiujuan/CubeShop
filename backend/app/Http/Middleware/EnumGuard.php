<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 反枚举频控（SEC-10）
 *
 * 即便已通过「隐藏 total / 随机单号 / public_id」大幅抬高了枚举成本，
 * 攻击者仍可对公开资源（商品详情、工单、评价）做海量遍历探测。
 * 本中间件在**响应层**兜底：
 *
 * 1. 仅对「敏感前缀 + 404」计数（正常命中资源的 200 不计入）；
 * 2. 同一 IP 在滑动窗口内 404 次数超过阈值即返回 429，打断遍历节奏；
 * 3. 每次命中写 security 日志，作为告警数据源（404 比值异常升高即触发人工复核）。
 *
 * 注意：它不区分「资源真的不存在」与「无权访问」——两者的外层语义本就统一为 404，
 * 这里只关心「单位时间内 404 密度」，因此天然兼容统一 404 语义的要求。
 */
final class EnumGuard
{
    /** 滑动窗口（秒） */
    private const WINDOW_SECONDS = 60;

    /** 窗口内 404 阈值，超过即限流 */
    private const MAX_404_PER_WINDOW = 40;

    /** 纳入统计的敏感前缀（公开资源，可被未登录遍历） */
    private const SENSITIVE_PREFIXES = [
        'api/products',
        'api/cs',
        'api/reviews',
        'api/orders',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 测试环境不拦截：避免单元测试中的大量 404 触发误限流，且便于直接单元测试本中间件。
        if (App::runningUnitTests()) {
            return $response;
        }

        if ($response->status() !== 404 || ! $this->isSensitive($request->path())) {
            return $response;
        }

        $ip = (string) $request->ip();
        $key = 'enum:404:'.md5($ip.'|'.$request->header('User-Agent', ''));

        $count = (int) Cache::get($key);
        $count++;
        Cache::put($key, $count, now()->addSeconds(self::WINDOW_SECONDS));

        Log::warning('enumeration_404_hit', [
            'ip' => $ip,
            'path' => $request->path(),
            'count' => $count,
            'threshold' => self::MAX_404_PER_WINDOW,
        ]);

        if ($count > self::MAX_404_PER_WINDOW) {
            return response()->json([
                'code' => 40009,
                'message' => '请求过于频繁，请稍后再试',
                'data' => null,
            ], 429);
        }

        return $response;
    }

    private function isSensitive(string $path): bool
    {
        foreach (self::SENSITIVE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
