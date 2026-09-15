<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 支付（API 文档 7 / Roadmap P5）
 */
class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private PaymentService $payments)
    {
    }

    /** 发起支付：POST /payments {order_no, channel} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => ['required', 'string'],
            'channel' => ['required', 'string', 'in:wechat,alipay'],
        ]);

        $order = Order::where('order_no', $data['order_no'])->first();
        if (! $order) {
            throw BusinessException::notFound('订单不存在');
        }

        [$payment, $payParams] = $this->payments->createPayment($order, $request->user()->id, $data['channel']);

        return $this->success([
            'payment_no' => $payment->payment_no,
            'amount' => (string) $payment->amount,
            'channel' => $payment->channel,
            'status' => $payment->status,
            'pay_params' => $payParams,
        ]);
    }

    /** 支付状态查询（前端轮询）：GET /payments/{paymentNo} */
    public function show(Request $request, string $paymentNo): JsonResponse
    {
        $payment = $this->payments->queryByNo($paymentNo, $request->user()->id);

        return $this->success([
            'payment_no' => $payment->payment_no,
            'order_no' => $payment->order_no,
            'channel' => $payment->channel,
            'amount' => (string) $payment->amount,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 渠道回调（API 文档 7.2）：POST /payments/callback/{channel}
     * 无需用户 Token，验签 + 幂等；返回渠道所需的应答。
     */
    public function callback(Request $request, string $channel): JsonResponse
    {
        $result = $this->payments->handleCallback($channel, (array) $request->input());

        return $this->success($result, $result['ok'] ? 'ok' : 'fail');
    }

    /**
     * 沙箱模拟渠道通知：POST /payments/sandbox/{paymentNo}
     * 开发环境模拟「用户在渠道完成支付」——内部生成签名走真实回调逻辑。
     */
    public function sandbox(Request $request, string $paymentNo): JsonResponse
    {
        $result = $this->payments->sandboxNotify($paymentNo, $request->input('result', 'success'));

        return $this->success($result);
    }
}
