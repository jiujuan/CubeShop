<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Dto\CallbackResult;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\RefundQueryResult;
use App\Services\Payment\Dto\TestResult;
use App\Services\Payment\Dto\ChannelTransaction;
use App\Services\Payment\Dto\StatementResult;
use Illuminate\Http\Request;
use RuntimeException;

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
    /** 测试可注入的合成账单（ChannelTransaction[]），消费后清空 */
    public static array $syntheticBill = [];
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

        // SEC-02 fail-closed：未配置密钥时一律拒绝，避免「空密钥 = 任何人都能算出签名」
        if (self::secret() === '') {
            return CallbackResult::fail('支付签名密钥未配置，拒绝回调', $payload);
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

    public function refund(Payment $payment, string $amount, string $reason, array $config, ?string $outRefundNo = null): RefundResult
    {
        return RefundResult::success($outRefundNo ?? 'MOCK'.strtoupper(bin2hex(random_bytes(6))));
    }

    /** 模拟网关退款即时成功，无远程查单；返回 unsupported 由编排层跳过轮询 */
    public function queryRefund(Payment $payment, string $outRefundNo, array $config): RefundQueryResult
    {
        return RefundQueryResult::fail('unsupported');
    }

    public function testConnection(array $config): TestResult
    {
        return TestResult::ok('本地模拟网关无需外部连通性测试');
    }

    /** HMAC-SHA256(payment_no|channel_trade_no|amount|status) */
    public static function sign(string $paymentNo, string $channelTradeNo, string $amount, string $status): string
    {
        $secret = self::secret();

        // SEC-02 fail-closed：空密钥下 HMAC 可被任何人复算，宁可报错也不生成可预测的签名
        if ($secret === '') {
            throw new RuntimeException('PAY_SIGN_SECRET 未配置，拒绝生成支付回调签名');
        }

        return hash_hmac('sha256', implode('|', [$paymentNo, $channelTradeNo, $amount, $status]), $secret);
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

    /**
     * 回调验签密钥（SEC-02）
     *
     * 历史实现为 `env('PAY_SIGN_SECRET', '<硬编码默认密钥>')`——默认值写在入库源码中，
     * 生产未覆盖时任何人都能算出合法签名、伪造「支付成功」回调。现改为**无默认值**：
     * 未配置时返回空串，由两道防线兜底——
     *   ① AppServiceProvider 启动 fail-fast（非 local/testing 的 HTTP 请求直接 500）；
     *   ② sign()/verifyCallback() fail-closed（空密钥不签名、不放行）。
     */
    public static function secret(): string
    {
        return (string) config('payments.secret', '');
    }
    /**
     * 拉取渠道日账单（A7-支付渠道对账，L1 模拟）
     *
     * 默认用本地该渠道当日 success 支付单合成一份自洽账单；
     * 测试可注入 static $syntheticBill（ChannelTransaction[]）以模拟「渠道有、本地无」等场景。
     */
    public function downloadBill(string $billDate, array $config): StatementResult
    {
        if (self::$syntheticBill !== []) {
            $txns = self::$syntheticBill;
            self::$syntheticBill = [];

            return StatementResult::success($txns);
        }

        $payments = Payment::query()
            ->where('channel', $this->channel())
            ->where('status', Payment::STATUS_SUCCESS)
            ->whereDate('paid_at', $billDate)
            ->get();

        $txns = $payments->map(function (Payment $p) {
            return new ChannelTransaction(
                (string) $p->channel_trade_no,
                $p->payment_no,
                (string) $p->amount,
                'paid',
                $p->paid_at,
            );
        })->all();

        return StatementResult::success($txns);
    }

}
