<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Common\NoGeneratorService;
use App\Services\Order\OrderService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 支付服务（Roadmap P5）
 *
 * 沙箱模式：无真实微信/支付宝商户号时，用 HMAC 签名模拟渠道回调
 * - POST /payments 生成支付单 + pay_params
 * - POST /payments/sandbox/{payment_no} 模拟渠道通知（内部生成签名走真实回调逻辑）
 * - POST /payments/callback/{channel} 回调入口：验签 + 幂等 + 更新订单与库存
 *
 * 支付成功：payments.status=success → orders=paid（状态机）→ 锁定库存确认扣减
 */
class PaymentService
{
    public function __construct(
        private NoGeneratorService $noGenerator,
        private OrderService $orders,
    ) {
    }

    /** 签名密钥（生产应配置 PAY_SIGN_SECRET） */
    private function secret(): string
    {
        return (string) config('payments.secret', env('PAY_SIGN_SECRET', 'cubeshop-sandbox-secret'));
    }

    /** 是否启用沙箱（本地/开发环境模拟渠道） */
    public static function sandboxEnabled(): bool
    {
        return (bool) config('payments.sandbox', env('PAYMENT_SANDBOX', true));
    }

    /** 生成回调验签：HMAC-SHA256(payment_no|channel_trade_no|amount|status) */
    public function sign(string $paymentNo, string $channelTradeNo, string $amount, string $status = Payment::STATUS_SUCCESS): string
    {
        return hash_hmac('sha256', implode('|', [$paymentNo, $channelTradeNo, $amount, $status]), $this->secret());
    }

    /**
     * 发起支付（API 文档 7.1）：生成支付单，同一订单存在待支付单则复用
     *
     * @return array{payment: Payment, pay_params: array}
     */
    public function createPayment(Order $order, int $userId, string $channel): array
    {
        if (! in_array($channel, ['wechat', 'alipay'], true)) {
            throw BusinessException::badRequest('不支持的支付渠道');
        }
        if ($order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }
        if (! $order->isPendingPayment()) {
            throw BusinessException::conflict('订单当前状态不可支付');
        }

        $existing = Payment::where('order_id', $order->id)
            ->where('status', Payment::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return [$existing, $this->buildPayParams($existing)];
        }

        $payment = Payment::create([
            'payment_no' => $this->noGenerator->generatePaymentNo(),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $userId,
            'channel' => $channel,
            'amount' => $order->pay_amount,
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->log($payment, 'create', ['channel' => $channel, 'amount' => (string) $order->pay_amount]);

        return [$payment, $this->buildPayParams($payment)];
    }

    private function buildPayParams(Payment $payment): array
    {
        return [
            'mode' => 'sandbox',
            'channel' => $payment->channel,
            'payment_no' => $payment->payment_no,
            'amount' => (string) $payment->amount,
            // 沙箱：前端拿该地址引导用户确认；生产环境替换为渠道 SDK 参数
            'sandbox_pay_url' => "/api/payments/sandbox/{$payment->payment_no}",
        ];
    }

    /**
     * 渠道回调处理（API 文档 7.2）：验签、幂等、事务更新支付与订单
     *
     * @param  array  $payload  {payment_no, channel_trade_no, amount, status, sign}
     */
    public function handleCallback(string $channel, array $payload): array
    {
        $paymentNo = (string) ($payload['payment_no'] ?? '');
        $tradeNo = (string) ($payload['channel_trade_no'] ?? '');
        $amount = (string) ($payload['amount'] ?? '');
        $status = (string) ($payload['status'] ?? Payment::STATUS_SUCCESS);
        $sign = (string) ($payload['sign'] ?? '');

        $payment = Payment::where('payment_no', $paymentNo)->first();

        // 1. 验签（支付单不存在时也返回失败，不抛异常——渠道侧需收到处理结果）
        if (! $payment || $payment->channel !== $channel) {
            return ['ok' => false, 'message' => '支付单不存在或渠道不匹配'];
        }

        if (! hash_equals($this->sign($paymentNo, $tradeNo, $amount, $status), $sign)) {
            $this->log($payment, 'callback', $payload, ['ok' => false, 'message' => '验签失败']);

            return ['ok' => false, 'message' => '验签失败'];
        }

        // 2. 金额一致性：回调金额必须与支付单金额一致（防低金额签名入账整单）
        if (bccomp($amount, (string) $payment->amount, 2) !== 0) {
            $this->log($payment, 'callback', $payload, ['ok' => false, 'message' => '回调金额与支付单金额不一致']);

            return ['ok' => false, 'message' => '回调金额与支付单金额不一致'];
        }

        // 3. 幂等：已成功直接返回（仍记录审计日志，渠道重发通知需留痕）
        if ($payment->status === Payment::STATUS_SUCCESS && $status === Payment::STATUS_SUCCESS) {
            $this->log($payment, 'callback', $payload, ['ok' => true, 'message' => '已处理（幂等跳过）']);

            return ['ok' => true, 'message' => '已处理（幂等跳过）'];
        }
        if ($payment->status !== Payment::STATUS_PENDING) {
            return ['ok' => false, 'message' => '支付单状态不允许更新'];
        }

        // 4. 事务：更新支付单 + 订单 + 确认扣减库存
        $paidOrder = null;
        try {
            DB::transaction(function () use ($payment, $status, $tradeNo, &$paidOrder) {
                // 条件更新防并发回调
                $affected = Payment::whereKey($payment->id)
                    ->where('status', Payment::STATUS_PENDING)
                    ->update([
                        'status' => $status,
                        'channel_trade_no' => $tradeNo,
                        'paid_at' => $status === Payment::STATUS_SUCCESS ? now() : null,
                        'updated_at' => now(),
                    ]);
                if ($affected === 0) {
                    throw BusinessException::conflict('支付单已处理');
                }
                $payment->refresh();

                /** @var Order $order */
                $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();

                if ($status === Payment::STATUS_SUCCESS) {
                    // 锁定库存 → 确认扣减（可售不变，锁定减少）
                    foreach ($order->items()->get() as $item) {
                        if ($item->sku_id) {
                            app(\App\Services\Inventory\InventoryService::class)
                                ->deduct($item->sku_id, $item->quantity, 'order', $order->id, '支付成功确认扣减');
                        }
                    }
                    $this->orders->transitionTo($order, Order::STATUS_PAID, null, 'order');
                    $paidOrder = $order;
                } elseif ($status === Payment::STATUS_CLOSED) {
                    $this->orders->transitionTo($order, Order::STATUS_CANCELLED, '支付关闭', 'order');
                }
                // failed：仅记录支付单失败，订单保持待支付可重新发起
            });
        } catch (BusinessException $e) {
            $this->log($payment, 'callback', $payload, ['ok' => false, 'message' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => '支付处理失败'];
        }

        // V1.1 F02 / T-018：事务提交后派发支付成功事件（通知买家，失败不影响支付结果）
        if ($paidOrder !== null) {
            event(new \App\Events\OrderPaid($paidOrder));
        }

        $this->log($payment, 'callback', $payload, ['ok' => true, 'status' => $status]);

        return ['ok' => true, 'message' => 'ok', 'status' => $status];
    }

    /**
     * 沙箱模拟渠道通知：内部生成签名后走真实回调逻辑（仅 sandbox 启用时可用）
     */
    public function sandboxNotify(string $paymentNo, string $result = Payment::STATUS_SUCCESS): array
    {
        if (! self::sandboxEnabled()) {
            throw BusinessException::forbidden('沙箱支付未启用');
        }

        $payment = Payment::where('payment_no', $paymentNo)->first();
        if (! $payment) {
            throw BusinessException::notFound('支付单不存在');
        }

        $tradeNo = 'SANDBOX'.strtoupper(bin2hex(random_bytes(8)));
        $payload = [
            'payment_no' => $paymentNo,
            'channel_trade_no' => $tradeNo,
            'amount' => (string) $payment->amount,
            'status' => $result,
            'sign' => $this->sign($paymentNo, $tradeNo, (string) $payment->amount, $result),
        ];

        return $this->handleCallback($payment->channel, $payload);
    }

    /** 支付状态查询（前端轮询，API 文档 7.3） */
    public function queryByNo(string $paymentNo, int $userId): Payment
    {
        $payment = Payment::where('payment_no', $paymentNo)
            ->where('user_id', $userId)
            ->first();

        if (! $payment) {
            throw BusinessException::notFound('支付单不存在');
        }

        return $payment;
    }

    /** 关闭订单的全部待支付单（订单取消时调用） */
    public static function closePendingForOrder(int $orderId): void
    {
        Payment::where('order_id', $orderId)
            ->where('status', Payment::STATUS_PENDING)
            ->update(['status' => Payment::STATUS_CLOSED, 'updated_at' => now()]);
    }

    /**
     * 后台人工关闭支付单（权限 payment.manage）
     *
     * 仅待支付（pending）可关闭；成功/失败/已关闭均拒绝。
     * 关闭只作用于支付单本身，不联动取消订单——买家仍可重新发起支付。
     *
     * @throws BusinessException 状态不允许关闭（40009）/ 支付单不存在（40004）
     */
    public function close(Payment $payment, int $adminId, ?string $reason = null): Payment
    {
        if ($payment->status !== Payment::STATUS_PENDING) {
            throw BusinessException::conflict('仅待支付状态的支付单可关闭');
        }

        $affected = DB::transaction(function () use ($payment) {
            $rows = Payment::whereKey($payment->id)
                ->where('status', Payment::STATUS_PENDING)
                ->update(['status' => Payment::STATUS_CLOSED, 'updated_at' => now()]);

            return $rows;
        });

        if ($affected === 0) {
            throw BusinessException::conflict('支付单状态已变更，请刷新后重试');
        }

        $payment->refresh();

        $this->log($payment, PaymentLog::EVENT_CLOSE, [
            'admin_id' => $adminId,
            'reason' => $reason,
        ], ['ok' => true, 'status' => Payment::STATUS_CLOSED]);

        return $payment;
    }

    /** 记录支付日志 */
    private function log(Payment $payment, string $event, array $request, array $response = null): void
    {
        PaymentLog::create([
            'payment_id' => $payment->id,
            'payment_no' => $payment->payment_no,
            'event' => $event,
            'request_data' => $request,
            'response_data' => $response,
            'created_at' => now(),
        ]);
    }
}
