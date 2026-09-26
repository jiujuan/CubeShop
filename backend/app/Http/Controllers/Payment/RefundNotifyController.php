<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Refund\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 退款异步通知（Phase 4，免 sanctum）
 *
 * 路由：POST /payments/{channel}/refund-notify
 * 仅微信支付具备异步退款回调（REFUND.SUCCESS / REFUND.ABNORMAL），由本控制器按渠道验签后
 * 交 RefundService 落库；支付宝 / 余额为同步退款，不会推送，直接 ACK 返回 200。
 */
class RefundNotifyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentChannelService $channels,
        private readonly PaymentGatewayFactory $factory,
        private readonly RefundService $refunds,
    ) {
    }

    /**
     * 退款通知入口
     *
     * 微信要求 HTTP 200 即视为成功（不解析响应体），故无论处理结果一律返回 200，
     * 避免渠道在异常时反复重推。
     */
    public function handle(Request $request, string $channel): JsonResponse
    {
        // 仅微信有异步退款回调；其余渠道为同步退款，不会推送，直接 ACK
        if ($channel !== Payment::CHANNEL_WECHAT) {
            return $this->ack('ignored_channel');
        }

        $gateway = $this->factory->make($channel);
        // 沙箱 / Mock 降级时网关无 verifyRefundCallback（不抛错），直接 ACK 防重推
        if (! method_exists($gateway, 'verifyRefundCallback')) {
            return $this->ack('mock');
        }

        $config = $this->channels->decryptedConfig($channel);
        $result = $gateway->verifyRefundCallback($request, $config);

        // 验签失败：不落状态，返回 200 让微信停止重推（避免无意义的反复推送）
        if (! $result->ok) {
            return $this->ack('verify_failed');
        }

        $this->refunds->applyChannelCallback($result);

        return $this->ack('success');
    }

    /**
     * 统一应答：HTTP 200 + {code:0}，兼容微信「只认状态码」的退款通知语义
     */
    private function ack(string $state): JsonResponse
    {
        return $this->success(['state' => $state], 'ok');
    }
}
