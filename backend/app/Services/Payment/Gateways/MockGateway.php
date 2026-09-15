<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Dto\CallbackResult;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\TestResult;
use Illuminate\Http\Request;

/**
 * L1 本地模拟网关（收银台方案 §8.2）
 *
 * 零外部依赖：create() 返回模拟支付地址，前端/测试调 /payments/sandbox/{no} 触发
 * 自签回调，走与真实渠道完全一致的验签 → 金额校验 → 幂等 → 状态机链路。
 *
 * 同时作为「沙箱模式下微信/支付宝的代理实现」：构造时传入被代理的渠道标识。
 */
class MockGateway implements PaymentGateway
{
    public function __construct(private readonly string $proxyChannel = Payment::CHANNEL_MOCK)
    {
    }

    public function channel(): string
    {
        return $this->proxyChannel;
    }

    public function create(Payment $payment, array $context, array $config): PayParams
    {
        return PayParams::mock("/api/payments/sandbox/{$payment->payment_no}", [
            // 兼容 V1.1 沙箱响应结构（mode / channel / payment_no / amount）
            'mode' => 'sandbox',
            'channel' => $payment->channel,
            'payment_no' => $payment->payment_no,
            'amount' => (string) $payment->amount,
        ]);
    }

    public function verifyCallback(Request $request, array $config): CallbackResult
    {
        $payload = [
            'payment_no' => (string) $request->input('payment_no', ''),
            'channel_trade_no' => (string) $request->input('channel_trade_no', ''),
            'amount' => (string) $request->input('amount', ''),
            'status' => (string) $request->input('status', Payment::STATUS_SUCCESS),
        ];
        $sign = (string) $request->input('sign', '');

        if ($payload['payment_no'] === '') {
            return CallbackResult::fail('缺少 payment_no', $payload);
        }

        $expected = self::sign(
            $payload['payment_no'],
            $payload['channel_trade_no'],
            $payload['amount'],
            $payload['status'],
        );

        if (! hash_equals($expected, $sign)) {
            return CallbackResult::fail('验签失败', $payload + ['sign' => $sign]);
        }

        return CallbackResult::success(
            $payload['payment_no'],
            $payload['channel_trade_no'],
            $payload['amount'],
            $payload['status'],
            $payload,
        );
    }

    public function query(Payment $payment, array $config): QueryResult
    {
        return QueryResult::success($payment->status, $payment->channel_trade_no, (string) $payment->amount);
    }

    public function refund(Payment $payment, string $amount, string $reason, array $config): RefundResult
    {
        return RefundResult::success('MOCK'.strtoupper(bin2hex(random_bytes(6))));
    }

    public function testConnection(array $config): TestResult
    {
        return TestResult::ok('本地模拟网关无需外部连通性测试');
    }

    /** HMAC-SHA256(payment_no|channel_trade_no|amount|status) */
    public static function sign(string $paymentNo, string $channelTradeNo, string $amount, string $status): string
    {
        return hash_hmac('sha256', implode('|', [$paymentNo, $channelTradeNo, $amount, $status]), self::secret());
    }

    /** 生成一笔模拟回调的完整报文（PaymentService::sandboxNotify 使用） */
    public function buildNotifyPayload(Payment $payment, string $result): array
    {
        $tradeNo = 'SANDBOX'.strtoupper(bin2hex(random_bytes(8)));

        return [
            'payment_no' => $payment->payment_no,
            'channel_trade_no' => $tradeNo,
            'amount' => (string) $payment->amount,
            'status' => $result,
            'sign' => self::sign($payment->payment_no, $tradeNo, (string) $payment->amount, $result),
        ];
    }

    public static function secret(): string
    {
        return (string) config('payments.secret', env('PAY_SIGN_SECRET', 'cubeshop-sandbox-secret'));
    }
}
