<?php

namespace App\Services\Payment;

use App\Events\OrderPaid;
use App\Exceptions\BusinessException;
use App\Models\BalanceRecharge;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\PaymentLog;
use App\Services\Common\ConfigService;
use App\Services\Common\NoGeneratorService;
use App\Services\Inventory\InventoryService;
use App\Services\Order\OrderService;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Gateways\MockGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 支付服务（编排层，收银台方案 §3.1 / §6）
 *
 * 职责：校验 → 建单 → 调网关 → 落日志 → 驱动订单状态机 / 余额入账
 *
 * 业务类型 biz_type：
 * - order    ：订单支付（确认扣减库存 + 订单转 paid）
 * - recharge ：余额充值（BalanceService::creditForRecharge 入账）
 *
 * 网关只返回标准化结果，订单状态流转与入账统一在这里完成（§3.2 关键约束）。
 */
class PaymentService
{
    public function __construct(
        private readonly NoGeneratorService $noGenerator,
        private readonly OrderService $orders,
        private readonly PaymentChannelService $channels,
        private readonly PaymentGatewayFactory $gateways,
        private readonly BalanceService $balances,
        private readonly ConfigService $config,
    ) {
    }

    /** 是否启用沙箱（本地/开发环境模拟渠道） */
    public static function sandboxEnabled(): bool
    {
        return (bool) config('payments.sandbox', env('PAYMENT_SANDBOX', true));
    }

    /** 生成回调验签：HMAC-SHA256(payment_no|channel_trade_no|amount|status)（Mock 网关沿用） */
    public function sign(string $paymentNo, string $channelTradeNo, string $amount, string $status = Payment::STATUS_SUCCESS): string
    {
        return MockGateway::sign($paymentNo, $channelTradeNo, $amount, $status);
    }

    /**
     * 发起订单支付：生成支付单，同一订单存在待支付单则复用（并允许切换渠道）
     *
     * @return array{payment: Payment, pay_params: array}
     */
    public function createPayment(Order $order, int $userId, string $channel, array $extra = []): array
    {
        $this->assertChannelEnabled($channel);

        if ($order->user_id !== $userId) {
            throw BusinessException::notFound('订单不存在');
        }
        if (! $order->isPendingPayment()) {
            throw BusinessException::conflict('订单当前状态不可支付');
        }

        $existing = $this->reusablePayment(
            Payment::where('order_id', $order->id),
        );

        $payment = $existing ?: Payment::create([
            'payment_no' => $this->noGenerator->generatePaymentNo(),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $userId,
            'channel' => $channel,
            'amount' => $order->pay_amount,
            'status' => Payment::STATUS_PENDING,
            'biz_type' => Payment::BIZ_TYPE_ORDER,
            'biz_no' => $order->order_no,
        ]);

        if ($existing && $existing->channel !== $channel) {
            // 切换渠道：同一时刻只允许一笔可付单，直接改渠道而不新建（§7.4）
            $existing->forceFill(['channel' => $channel, 'status' => Payment::STATUS_PENDING])->save();
            $payment = $existing->fresh();
        }

        $this->log($payment, PaymentLog::EVENT_CREATE, [
            'channel' => $channel,
            'amount' => (string) $payment->amount,
            'biz_type' => $payment->biz_type,
        ]);

        $params = $this->invokeGateway($payment, ['order' => $order, 'extra' => $extra], $channel);

        return [$payment->fresh(), $params->toArray()];
    }

    /**
     * 发起余额充值支付（P6 充值链路，与订单支付共用同一套网关与结果页）
     *
     * @return array{payment: Payment, pay_params: array}
     */
    public function createRechargePayment(BalanceRecharge $recharge, string $channel, array $extra = []): array
    {
        $this->assertChannelEnabled($channel);

        if ($channel === Payment::CHANNEL_BALANCE) {
            throw BusinessException::badRequest('充值不支持余额支付');
        }
        if ($recharge->status !== BalanceRecharge::STATUS_PENDING) {
            throw BusinessException::conflict('充值单当前状态不可支付');
        }

        $payment = Payment::where('biz_type', Payment::BIZ_TYPE_RECHARGE)
            ->where('biz_no', $recharge->recharge_no)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING])
            ->orderByDesc('id')
            ->first();

        if (! $payment) {
            $payment = Payment::create([
                'payment_no' => $this->noGenerator->generatePaymentNo(),
                'order_id' => null,
                'order_no' => null,
                'user_id' => $recharge->user_id,
                'channel' => $channel,
                'amount' => $recharge->amount,
                'status' => Payment::STATUS_PENDING,
                'biz_type' => Payment::BIZ_TYPE_RECHARGE,
                'biz_no' => $recharge->recharge_no,
            ]);

            $recharge->forceFill(['payment_id' => $payment->id, 'channel' => $channel])->save();
        } elseif ($payment->channel !== $channel) {
            $payment->forceFill(['channel' => $channel, 'status' => Payment::STATUS_PENDING])->save();
            $recharge->forceFill(['channel' => $channel])->save();
        }

        $this->log($payment, PaymentLog::EVENT_CREATE, [
            'channel' => $channel,
            'amount' => (string) $payment->amount,
            'biz_type' => Payment::BIZ_TYPE_RECHARGE,
        ]);

        $params = $this->invokeGateway($payment, ['recharge' => $recharge, 'extra' => $extra], $channel);

        return [$payment->fresh(), $params->toArray()];
    }

    /**
     * 可复用的支付单：pending / reviewing 直接复用，failed 重置为 pending 后复用
     *
     * 同一业务单据同时只保留一笔可付单：切换渠道或核账驳回后重新提交都复用原单，
     * 避免一个订单下堆积多笔支付单（§7.4）。
     */
    private function reusablePayment(\Illuminate\Database\Eloquent\Builder $query): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = $query
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING, Payment::STATUS_FAILED])
            ->orderByDesc('id')
            ->first();

        if ($payment && $payment->status === Payment::STATUS_FAILED) {
            $payment->forceFill(['status' => Payment::STATUS_PENDING])->save();
        }

        return $payment;
    }

    /**
     * 调网关 create()，并按返回类型处理同步结果
     */
    private function invokeGateway(Payment $payment, array $context, string $channel): PayParams
    {
        $gateway = $this->gateways->make($channel);
        $config = $this->channels->decryptedConfig($channel);

        $params = match (true) {
            // 线下转账：先落凭证再建单状态 reviewing
            $channel === Payment::CHANNEL_OFFLINE => $this->createOffline($payment, $gateway, $context, $config),
            default => $gateway->create($payment, $context, $config),
        };

        if ($params->type === PayParams::TYPE_DIRECT) {
            // 余额支付：同步完成（网关已扣款），驱动状态机与入账
            $this->applySuccess($payment, $payment->payment_no);
        }

        return $params;
    }

    /** 线下转账：写凭证字段 → 支付单置 reviewing → 返回收款账户 */
    private function createOffline(Payment $payment, PaymentGateway $gateway, array $context, array $config): PayParams
    {
        $params = $gateway->create($payment, $context, $config);
        $extra = (array) ($context['extra'] ?? []);

        $payment->forceFill([
            'status' => Payment::STATUS_REVIEWING,
            'payer_name' => $extra['payer_name'] ?? null,
            'payer_account' => $extra['payer_account'] ?? null,
            'transfer_no' => $extra['transfer_no'] ?? null,
            'transferred_at' => $extra['transferred_at'] ?? null,
            'voucher_url' => $extra['voucher_url'] ?? null,
        ])->save();

        if ($payment->isRecharge()) {
            BalanceRecharge::where('recharge_no', $payment->biz_no)->update([
                'status' => BalanceRecharge::STATUS_REVIEWING,
                'payer_name' => $extra['payer_name'] ?? null,
                'payer_account' => $extra['payer_account'] ?? null,
                'transfer_no' => $extra['transfer_no'] ?? null,
                'transferred_at' => $extra['transferred_at'] ?? null,
                'voucher_url' => $extra['voucher_url'] ?? null,
            ]);
        }

        return $params;
    }

    /**
     * 渠道回调处理：验签 → 金额一致性 → 幂等 → 事务更新 + 业务分流
     *
     * @param  array|Request  $input
     */
    public function handleCallback(string $channel, array|Request $input): array
    {
        $request = $input instanceof Request
            ? $input
            : Request::create('/api/payments/callback/'.$channel, 'POST', $input);

        $gateway = $this->gateways->make($channel);
        $config = $this->channels->decryptedConfig($channel);

        $result = $gateway->verifyCallback($request, $config);

        $payment = $result->paymentNo !== ''
            ? Payment::where('payment_no', $result->paymentNo)->first()
            : null;

        if (! $payment || $payment->channel !== $channel) {
            return ['ok' => false, 'message' => '支付单不存在或渠道不匹配'];
        }
        if (! $result->ok) {
            $this->log($payment, PaymentLog::EVENT_CALLBACK, $result->raw, ['ok' => false, 'message' => $result->message]);

            return ['ok' => false, 'message' => $result->message];
        }

        // 金额一致性：回调金额必须与支付单金额一致（防低金额签名入账整单）
        if (bccomp($result->amount, (string) $payment->amount, 2) !== 0) {
            $this->log($payment, PaymentLog::EVENT_CALLBACK, $result->raw, ['ok' => false, 'message' => '回调金额与支付单金额不一致']);

            return ['ok' => false, 'message' => '回调金额与支付单金额不一致'];
        }

        // 幂等：已成功直接返回（仍记录审计日志，渠道重发通知需留痕）
        if ($payment->status === Payment::STATUS_SUCCESS && $result->status === Payment::STATUS_SUCCESS) {
            $this->log($payment, PaymentLog::EVENT_CALLBACK, $result->raw, ['ok' => true, 'message' => '已处理（幂等跳过）']);

            return ['ok' => true, 'message' => '已处理（幂等跳过）'];
        }

        try {
            if ($result->status === Payment::STATUS_SUCCESS) {
                $this->applySuccess($payment, $result->channelTradeNo);
            } elseif ($result->status === Payment::STATUS_CLOSED) {
                $this->applyClosed($payment);
            } else {
                $this->applyFailed($payment);
            }
        } catch (BusinessException $e) {
            $this->log($payment, PaymentLog::EVENT_CALLBACK, $result->raw, ['ok' => false, 'message' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => '支付处理失败'];
        }

        $this->log($payment, PaymentLog::EVENT_CALLBACK, $result->raw, ['ok' => true, 'status' => $result->status]);

        return ['ok' => true, 'message' => 'ok', 'status' => $result->status];
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

        $payload = (new MockGateway($payment->channel))->buildNotifyPayload($payment, $result);

        return $this->handleCallback($payment->channel, $payload);
    }

    /**
     * 主动查单补偿（回调丢失时由前端「同步」按钮或调度任务触发，§7.2）
     */
    public function sync(Payment $payment): array
    {
        $gateway = $this->gateways->make($payment->channel);
        $result = $gateway->query($payment, $this->channels->decryptedConfig($payment->channel));

        $this->log($payment, PaymentLog::EVENT_QUERY, [
            'channel' => $payment->channel,
            'payment_no' => $payment->payment_no,
        ], ['ok' => $result->ok, 'status' => $result->status, 'message' => $result->message]);

        if (! $result->ok) {
            return ['ok' => false, 'message' => $result->message];
        }

        if ($result->status === Payment::STATUS_SUCCESS && $payment->status !== Payment::STATUS_SUCCESS) {
            $this->applySuccess($payment, $result->channelTradeNo ?? $payment->channel_trade_no);

            return ['ok' => true, 'status' => Payment::STATUS_SUCCESS, 'message' => '查单已补单'];
        }

        return ['ok' => true, 'status' => $payment->fresh()->status, 'message' => 'ok'];
    }

    /* ------------------------------------------------------------------ */
    /* 主动查单调度支撑（§7.2）                                             */
    /* ------------------------------------------------------------------ */

    /** 主动查单最大尝试次数（配置 payment.query_max_attempts，默认 10） */
    public function queryMaxAttempts(): int
    {
        return max(1, $this->config->getInt('payment.query_max_attempts', 10));
    }

    /** 该支付单已「主动查单」的次数（依据 payment_logs event=query） */
    public function queryAttempts(Payment $payment): int
    {
        return PaymentLog::query()
            ->where('payment_id', $payment->id)
            ->where('event', PaymentLog::EVENT_QUERY)
            ->count();
    }

    /** 主动查单次数耗尽：标记失败并记日志（§7.2） */
    public function markQueryExhausted(Payment $payment): void
    {
        try {
            $this->applyFailed($payment);
        } catch (BusinessException) {
            return; // 已被并发处理（回调先到），无需再标记
        }

        $this->log($payment->fresh(), PaymentLog::EVENT_QUERY, [
            'reason' => 'query_max_attempts_exceeded',
            'attempts' => $this->queryAttempts($payment),
        ], ['ok' => false, 'message' => '主动查单超限，标记失败']);
    }

    /**
     * 线下转账核账（§6.2）
     *
     * 通过 → 支付单 success 并驱动订单/入账；驳回 → failed，用户可重新提交凭证或换渠道。
     */
    public function review(Payment $payment, int $adminId, bool $pass, ?string $remark = null): Payment
    {
        if ($payment->status !== Payment::STATUS_REVIEWING) {
            throw BusinessException::conflict('仅待核账的支付单可核账');
        }
        if (! $pass && trim((string) $remark) === '') {
            throw BusinessException::badRequest('驳回时请填写原因');
        }

        $payment->forceFill([
            'review_remark' => $remark,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
        ])->save();

        if (! $pass) {
            $this->applyFailed($payment);
        } else {
            $this->applySuccess($payment, $payment->channel_trade_no ?: $payment->payment_no);
        }

        $this->log($payment->fresh(), PaymentLog::EVENT_REVIEW, [
            'admin_id' => $adminId,
            'pass' => $pass,
            'remark' => $remark,
        ], ['ok' => true, 'status' => $payment->fresh()->status]);

        return $payment->fresh();
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

    /** 关闭充值单的全部待处理支付单（充值超时时调用，§7.3） */
    public static function closePendingForRecharge(BalanceRecharge $recharge): void
    {
        Payment::where('biz_type', Payment::BIZ_TYPE_RECHARGE)
            ->where('biz_no', $recharge->recharge_no)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING])
            ->update(['status' => Payment::STATUS_CLOSED, 'updated_at' => now()]);
    }

    /**
     * 后台人工关闭支付单（权限 payment.manage）
     *
     * 仅待支付（pending）可关闭；成功/失败/已关闭均拒绝。
     * 关闭只作用于支付单本身，不联动取消订单——买家仍可重新发起支付。
     */
    public function close(Payment $payment, int $adminId, ?string $reason = null): Payment
    {
        if ($payment->status !== Payment::STATUS_PENDING) {
            throw BusinessException::conflict('仅待支付状态的支付单可关闭');
        }

        $affected = DB::transaction(function () use ($payment) {
            return Payment::whereKey($payment->id)
                ->where('status', Payment::STATUS_PENDING)
                ->update(['status' => Payment::STATUS_CLOSED, 'updated_at' => now()]);
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

    /* ------------------------------------------------------------------ */
    /* 内部：状态应用（唯一改状态出口）                                      */
    /* ------------------------------------------------------------------ */

    /**
     * 支付成功：事务内更新支付单，并按 biz_type 驱动订单状态机或余额入账
     */
    private function applySuccess(Payment $payment, ?string $tradeNo): void
    {
        $paidOrder = null;

        DB::transaction(function () use ($payment, $tradeNo, &$paidOrder) {
            $affected = Payment::whereKey($payment->id)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING])
                ->update([
                    'status' => Payment::STATUS_SUCCESS,
                    'channel_trade_no' => $tradeNo,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw BusinessException::conflict('支付单已处理');
            }
            $payment->refresh();

            if ($payment->isOrder()) {
                /** @var Order $order */
                $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();
                if (! $order) {
                    throw BusinessException::notFound('订单不存在');
                }

                // 锁定库存 → 确认扣减（可售不变，锁定减少）
                foreach ($order->items()->get() as $item) {
                    if ($item->sku_id) {
                        app(InventoryService::class)
                            ->deduct($item->sku_id, $item->quantity, 'order', $order->id, '支付成功确认扣减');
                    }
                }

                $order = $this->orders->transitionTo($order, Order::STATUS_PAID, null, 'order');

                // 支付成功即进入发货队列（已支付 → 待发货），正常路径无需运营手工受理
                $this->orders->acceptForShipment(
                    $order,
                    operatorType: OrderLog::OPERATOR_SYSTEM,
                    reason: '支付成功，系统自动受理进入发货队列',
                );

                $paidOrder = $order;

                return;
            }

            // 余额充值：入账（幂等）+ 充值单置成功
            $recharge = BalanceRecharge::where('recharge_no', $payment->biz_no)->lockForUpdate()->first();
            if (! $recharge) {
                throw BusinessException::notFound('充值单不存在');
            }

            $this->balances->creditForRecharge($recharge);

            $recharge->forceFill([
                'status' => BalanceRecharge::STATUS_SUCCESS,
                'payment_id' => $payment->id,
                'paid_at' => now(),
            ])->save();
        });

        if ($paidOrder !== null) {
            event(new OrderPaid($paidOrder));
        }
    }

    /** 支付失败：仅支付单置 failed，订单保持待支付可重新发起 */
    private function applyFailed(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $affected = Payment::whereKey($payment->id)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING])
                ->update(['status' => Payment::STATUS_FAILED, 'updated_at' => now()]);

            if ($affected === 0) {
                throw BusinessException::conflict('支付单已处理');
            }

            if ($payment->isRecharge()) {
                BalanceRecharge::where('recharge_no', $payment->biz_no)
                    ->whereIn('status', [BalanceRecharge::STATUS_PENDING, BalanceRecharge::STATUS_REVIEWING])
                    ->update(['status' => BalanceRecharge::STATUS_FAILED, 'updated_at' => now()]);
            }
        });

        $payment->refresh();
    }

    /** 支付关闭：订单取消（订单支付场景） */
    private function applyClosed(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $affected = Payment::whereKey($payment->id)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_REVIEWING])
                ->update(['status' => Payment::STATUS_CLOSED, 'updated_at' => now()]);

            if ($affected === 0) {
                throw BusinessException::conflict('支付单已处理');
            }

            if ($payment->isOrder()) {
                $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();
                if ($order) {
                    $this->orders->transitionTo($order, Order::STATUS_CANCELLED, '支付关闭', 'order');
                }
            } elseif ($payment->isRecharge()) {
                BalanceRecharge::where('recharge_no', $payment->biz_no)
                    ->whereIn('status', [BalanceRecharge::STATUS_PENDING, BalanceRecharge::STATUS_REVIEWING])
                    ->update(['status' => BalanceRecharge::STATUS_CLOSED, 'updated_at' => now()]);
            }
        });

        $payment->refresh();
    }

    /** 渠道校验：必须已启用（防伪造未启用渠道） */
    private function assertChannelEnabled(string $channel): void
    {
        if (! in_array($channel, array_keys(Payment::CHANNEL_LABELS), true)) {
            throw BusinessException::badRequest('不支持的支付渠道');
        }
        if (! $this->channels->isEnabled($channel)) {
            throw BusinessException::badRequest('支付渠道未启用');
        }
        if ($channel === PaymentChannel::CHANNEL_MOCK && app()->environment('production')) {
            throw BusinessException::forbidden('生产环境禁止使用模拟支付渠道');
        }
    }

    /** 记录支付日志 */
    private function log(Payment $payment, string $event, array $request, ?array $response = null): void
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
