<?php

use App\Console\Commands\RefundSyncCommand;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundLog;
use App\Services\Order\OrderService;
use App\Services\Payment\Dto\RefundQueryResult;
use App\Services\Payment\Gateways\WechatGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Refund\RefundLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

afterEach(fn () => Mockery::close());

/**
 * Phase 4 单元测试：退款异步兜底轮询命令（RefundSyncCommand）
 *
 * 覆盖：查单 SUCCESS→success、ABNORMAL→failed、超时→failed(TIMEOUT)、近期 processing 保持、
 * 以及仅微信渠道进入轮询范围。网关以 Mockery 桩替换，隔离命令分支逻辑。
 */

function phase4CmdFixture(int $createdAtMinutesAgo = 5): array
{
    $user = createTestUser();
    $sku = createTestSku(stock: 20, price: '100.00');
    \App\Models\CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
    $address = \App\Models\UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);
    $order = $service->transitionTo($order, Order::STATUS_PAID);
    $order->update(['status' => Order::STATUS_REFUNDING]);

    // 命令按 order_id 查找成功支付单（不区分渠道），这里用微信支付单
    Payment::create([
        'payment_no' => 'PAY'.strtoupper((string) \Illuminate\Support\Str::random(16)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'channel' => Payment::CHANNEL_WECHAT,
        'amount' => $order->pay_amount,
        'status' => Payment::STATUS_SUCCESS,
        'biz_type' => Payment::BIZ_TYPE_ORDER,
        'biz_no' => $order->order_no,
        'paid_at' => now(),
    ]);

    return [$user, $sku, $order];
}

function phase4MakeWechatRefund(string $outRefundNo, int $createdAtMinutesAgo): Refund
{
    [, , $order] = phase4CmdFixture($createdAtMinutesAgo);

    $refund = Refund::create([
        'refund_no' => 'RF'.strtoupper(\Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $order->user_id,
        'type' => Refund::TYPE_REFUND,
        'amount' => '10.00',
        'status' => Refund::STATUS_PROCESSING,
        'channel' => Payment::CHANNEL_WECHAT,
        'payment_no' => 'PAY-WX-SYNC',
        'out_refund_no' => $outRefundNo,
    ]);
    $refund->created_at = now()->subMinutes($createdAtMinutesAgo);
    $refund->save();

    return $refund;
}

function phase4BindGatewayMock(RefundQueryResult $result): void
{
    $gateway = Mockery::mock(WechatGateway::class);
    $gateway->shouldReceive('queryRefund')->andReturn($result);

    $factory = Mockery::mock(PaymentGatewayFactory::class);
    $factory->shouldReceive('make')->with(Payment::CHANNEL_WECHAT)->andReturn($gateway);

    app()->bind(PaymentGatewayFactory::class, fn () => $factory);
}

// ---------------------------------------------------------------- 查单 SUCCESS

test('轮询查单返回 SUCCESS 则置 success 并转订单 refunded', function () {
    $refund = phase4MakeWechatRefund('R-SYNC-SUC-001', 5);
    $orderId = $refund->order_id;

    phase4BindGatewayMock(RefundQueryResult::success('SUCCESS', 'RF-SYNC-1'));

    Artisan::call('refunds:sync-processing');

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->channel_refund_no)->toBe('RF-SYNC-1')
        ->and(Order::whereKey($orderId)->value('status'))->toBe(Order::STATUS_REFUNDED);
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_QUERY)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- 查单 ABNORMAL

test('轮询查单返回 ABNORMAL 则置 failed', function () {
    $refund = phase4MakeWechatRefund('R-SYNC-ABN-001', 5);

    phase4BindGatewayMock(RefundQueryResult::success('ABNORMAL', 'RF-SYNC-2'));

    Artisan::call('refunds:sync-processing');

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_FAILED)
        ->and($refund->refund_status)->toBe('ABNORMAL');
});

// ---------------------------------------------------------------- 超时兜底

test('processing 超 30 分钟仍未确认则转 failed(TIMEOUT)', function () {
    $refund = phase4MakeWechatRefund('R-SYNC-TO-001', 40);

    // 渠道仍返回 PROCESSING，但已超过超时阈值
    phase4BindGatewayMock(RefundQueryResult::success('PROCESSING', 'RF-SYNC-3'));

    Artisan::call('refunds:sync-processing');

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_FAILED)
        ->and($refund->refund_status)->toBe('TIMEOUT');
});

// ---------------------------------------------------------------- 近期保持 processing

test('近期（未超阈值）的 processing 保持处理中', function () {
    $refund = phase4MakeWechatRefund('R-SYNC-REC-001', 5);

    phase4BindGatewayMock(RefundQueryResult::success('PROCESSING', 'RF-SYNC-4'));

    Artisan::call('refunds:sync-processing');

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_PROCESSING)
        ->and($refund->refund_status)->toBe('PROCESSING');
});

// ---------------------------------------------------------------- 仅微信进入轮询

test('非微信渠道的 processing 退款不在轮询范围', function () {
    [, , $order] = phase4CmdFixture(5);
    $refund = Refund::create([
        'refund_no' => 'RF'.strtoupper(\Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $order->user_id,
        'type' => Refund::TYPE_REFUND,
        'amount' => '10.00',
        'status' => Refund::STATUS_PROCESSING,
        'channel' => Payment::CHANNEL_BALANCE,
        'payment_no' => 'PAY-BAL-SYNC',
        'out_refund_no' => 'R-SYNC-BAL-001',
    ]);
    $refund->created_at = now()->subMinutes(40);
    $refund->save();

    // 不绑定微信网关：若被误扫会调用 factory->make 报缺桩
    Artisan::call('refunds:sync-processing');

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_PROCESSING);
});
