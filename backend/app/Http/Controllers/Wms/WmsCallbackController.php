<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Services\Wms\Callback\WmsCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WMS 回调入口（WMS 计划 P3 / Step 2，公开路由，无认证）
 *
 * **快进快出**：读原始 body → 同步编排（验签/IP/防重放/落痕/入队）→ 返回菜鸟格式。
 * 不做业务、不抛异常——所有失败都以菜鸟格式 `flag=failure` 返回（让对方重推），
 * 唯一例外是防重放（返回 success 让对方停止）。
 *
 * 响应格式（奇门网关约定）：
 * ```json
 * {"flag":"success","code":"0","message":"ok"}
 * ```
 */
class WmsCallbackController extends Controller
{
    public function __construct(private readonly WmsCallbackService $callbacks)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $supported = (array) config('wms.callback.providers', ['cainiao']);
        if (! in_array($provider, $supported, true)) {
            return response()->json([
                'flag' => 'failure',
                'code' => 'PROVIDER_UNSUPPORTED',
                'message' => "不支持的 WMS 提供方：{$provider}",
            ]);
        }

        $result = $this->callbacks->receive(
            $provider,
            (string) $request->getContent(),
            (array) $request->query(),
            (string) $request->ip(),
        );

        // 对方按 flag 判定，HTTP 恒 200（异常 HTTP 状态会触发对方无意义重试风暴）
        return response()->json($result);
    }
}
