<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\PaymentReconciliationDiff;
use App\Models\UserAddress;
use App\Services\Refund\RefundReconcileService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\Dto\RefundQueryResult;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => Mockery::close());

function makePaidOrderForReconcile(string $price = '100.00'): array
    {
        $user = createTestUser();
        $sku = createTestSku(stock: 20, price: $price);
        \App\Models\CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
        $address = UserAddress::create([
            'user_id' => $user->id,
            'contact_name' => 'a', 'contact_phone' => 'b',
            'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
        ]);

        $service = app(OrderService::class);
        $order = $service->createFromCart($user->id, $address->id, null, null);
        $order = $service->transitionTo($order, Order::STATUS_PAID);

        Payment::create([
            'payment_no' => 'PAY'.strtoupper((string) \Illuminate\Support\Str::random(16)),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $user->id,
            'channel' => Payment::CHANNEL_BALANCE,
            'amount' => $order->pay_amount,
            'status' => Payment::STATUS_SUCCESS,
            'biz_type' => Payment::BIZ_TYPE_ORDER,
            'biz_no' => $order->order_no,
            'paid_at' => now(),
        ]);

        return [$user, $sku, $order];
}

/**
 * 造一笔「订单 + 微信成功支付单 + 退款单」，渠道退款单号已持久化（对账可反查）。
 * 退款单 updated_at 落当天，命中默认对账日窗口。
 */
function seedRefundWithWechatPayment(string $refundStatus, string $channel = Payment::CHANNEL_WECHAT): array
{
    [$user, $sku, $order] = makePaidOrderForReconcile();

    Payment::create([
        'payment_no' => 'PAY'.strtoupper((string) \Illuminate\Support\Str::random(16)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'channel' => $channel,
        'amount' => $order->pay_amount,
        'status' => Payment::STATUS_SUCCESS,
        'biz_type' => Payment::BIZ_TYPE_ORDER,
        'biz_no' => $order->order_no,
        'paid_at' => now(),
    ]);

    $refund = Refund::create([
        'refund_no' => 'RF'.strtoupper((string) \Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => 'refund',
        'amount' => $order->pay_amount,
        'status' => $refundStatus,
        'channel' => $channel,
        'out_refund_no' => 'R'.strtoupper((string) \Illuminate\Support\Str::ulid()),
        'refund_status' => $refundStatus === Refund::STATUS_PROCESSING ? 'PROCESSING' : 'SUCCESS',
        'updated_at' => now(),
    ]);

    return [$refund, $order];
}

/** 用 mock 网关（queryRefund 固定返回 $result）组装对账服务 */
function makeReconcileService(RefundQueryResult $result): RefundReconcileService
{
    $gateway = Mockery::mock(MockGateway::class);
    $gateway->shouldReceive('queryRefund')->andReturn($result);

    $factory = Mockery::mock(PaymentGatewayFactory::class);
    $factory->shouldReceive('make')->andReturn($gateway);

    $channels = Mockery::mock(PaymentChannelService::class);
    $channels->shouldReceive('decryptedConfig')->andReturn([]);

    return new RefundReconcileService($factory, $channels);
}

test('本地 processing 但渠道已 SUCCESS → 开差异单', function () {
    [$refund] = seedRefundWithWechatPayment(Refund::STATUS_PROCESSING);

    $service = makeReconcileService(RefundQueryResult::success('SUCCESS', 'CH'.rand(1000, 9999)));
    $res = $service->run(now()->toDateString(), [Payment::CHANNEL_WECHAT]);

    expect($res['diff_count'])->toBe(1)
        ->and(PaymentReconciliationDiff::where('payment_no', $refund->refund_no)
            ->where('channel_trade_no', $refund->out_refund_no)
            ->where('diff_type', PaymentReconciliationDiff::TYPE_REFUND_STATUS_MISMATCH)
            ->where('status', PaymentReconciliationDiff::STATUS_PENDING)
            ->count())->toBe(1);
});

test('本地 success 但渠道 FAILED → 开差异单', function () {
    [$refund] = seedRefundWithWechatPayment(Refund::STATUS_SUCCESS);

    $service = makeReconcileService(RefundQueryResult::success('FAILED', 'CH'.rand(1000, 9999)));
    $res = $service->run(now()->toDateString(), [Payment::CHANNEL_WECHAT]);

    expect($res['diff_count'])->toBe(1);
});

test('本地 processing 渠道也 PROCESSING → 一致不开单', function () {
    seedRefundWithWechatPayment(Refund::STATUS_PROCESSING);

    $service = makeReconcileService(RefundQueryResult::success('PROCESSING', 'CH'.rand(1000, 9999)));
    $res = $service->run(now()->toDateString(), [Payment::CHANNEL_WECHAT]);

    expect($res['diff_count'])->toBe(0);
});

test('本地 success 渠道 SUCCESS → 一致不开单', function () {
    seedRefundWithWechatPayment(Refund::STATUS_SUCCESS);

    $service = makeReconcileService(RefundQueryResult::success('SUCCESS', 'CH'.rand(1000, 9999)));
    $res = $service->run(now()->toDateString(), [Payment::CHANNEL_WECHAT]);

    expect($res['diff_count'])->toBe(0);
});

test('渠道查单不支持(ok=false) → 跳过不开单', function () {
    seedRefundWithWechatPayment(Refund::STATUS_PROCESSING);

    $service = makeReconcileService(RefundQueryResult::fail());
    $res = $service->run(now()->toDateString(), [Payment::CHANNEL_WECHAT]);

    expect($res['diff_count'])->toBe(0);
});

test('refunds:reconcile 命令可运行并落差异单', function () {
    [$refund] = seedRefundWithWechatPayment(Refund::STATUS_PROCESSING);

    $gateway = Mockery::mock(MockGateway::class);
    $gateway->shouldReceive('queryRefund')->andReturn(RefundQueryResult::success('SUCCESS', 'CH1'));

    $factory = Mockery::mock(PaymentGatewayFactory::class);
    $factory->shouldReceive('make')->andReturn($gateway);

    $channels = Mockery::mock(PaymentChannelService::class);
    $channels->shouldReceive('decryptedConfig')->andReturn([]);

    $this->app->instance(PaymentGatewayFactory::class, $factory);
    $this->app->instance(PaymentChannelService::class, $channels);

    $this->artisan('refunds:reconcile', ['--date' => now()->toDateString(), '--channel' => 'wechat'])
        ->assertSuccessful();

    expect(PaymentReconciliationDiff::where('payment_no', $refund->refund_no)->count())->toBe(1);
});
