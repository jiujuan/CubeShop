<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\UserAddress;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 构造一笔「已创建、待支付」的真实订单
 */
function createPendingOrder(int $qty = 2, string $price = '50.00'): array
{
    $user = createTestUser();
    $sku = createTestSku(stock: 20, price: $price);
    CartItemSeed($user->id, $sku->id, $qty);
    $address = UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '路 1 号',
    ]);

    $order = app(OrderService::class)->createFromCart($user->id, $address->id, null, null);

    return [$user, $sku, $order];
}

function CartItemSeed(int $userId, int $skuId, int $qty): void
{
    \App\Models\CartItem::create(['user_id' => $userId, 'sku_id' => $skuId, 'quantity' => $qty]);
}

// PAY-U-01 签名生成与校验
test('sign 生成可复验的 HMAC 签名', function () {
    $service = app(PaymentService::class);
    $sign = $service->sign('PAY123', 'TRADE1', '60.00');

    expect($sign)->toBe($service->sign('PAY123', 'TRADE1', '60.00'))
        ->and($sign)->not->toBe($service->sign('PAY123', 'TRADE1', '61.00'));
});

// PAY-U-02 发起支付：生成支付单并复用待支付单
test('createPayment 创建支付单', function () {
    [$user, $sku, $order] = createPendingOrder();

    [$payment, $params] = app(PaymentService::class)->createPayment($order, $user->id, 'wechat');

    expect($payment->status)->toBe(Payment::STATUS_PENDING)
        ->and($payment->order_no)->toBe($order->order_no)
        ->and((string) $payment->amount)->toBe($order->pay_amount)
        ->and($params['mode'])->toBe('sandbox');

    // 再次发起 → 复用同一支付单
    [$payment2] = app(PaymentService::class)->createPayment($order, $user->id, 'alipay');
    expect($payment2->id)->toBe($payment->id);
});

test('createPayment 拒绝非本人订单与非法渠道', function () {
    [$user, $sku, $order] = createPendingOrder();
    $other = createTestUser('x');

    app(PaymentService::class)->createPayment($order, $other->id, 'wechat');
})->throws(App\Exceptions\BusinessException::class, '订单不存在');

test('createPayment 拒绝不支持的渠道', function () {
    [$user, $sku, $order] = createPendingOrder();

    app(PaymentService::class)->createPayment($order, $user->id, 'bitcoin');
})->throws(App\Exceptions\BusinessException::class);

// PAY-U-03 回调：验签失败拒绝
test('回调验签失败返回 ok=false', function () {
    [$user, $sku, $order] = createPendingOrder();
    $service = app(PaymentService::class);
    [$payment] = $service->createPayment($order, $user->id, 'wechat');

    $result = $service->handleCallback('wechat', [
        'payment_no' => $payment->payment_no,
        'channel_trade_no' => 'T1',
        'amount' => (string) $payment->amount,
        'status' => 'success',
        'sign' => 'invalid-sign',
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_PENDING);
});

// PAY-U-04 回调成功：状态机 + 库存扣减
test('回调成功后支付与订单状态更新且库存确认扣减', function () {
    [$user, $sku, $order] = createPendingOrder();
    $service = app(PaymentService::class);
    [$payment] = $service->createPayment($order, $user->id, 'wechat');

    $result = $service->sandboxNotify($payment->payment_no);

    expect($result['ok'])->toBeTrue();

    $order = $order->fresh();
    expect($order->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and($order->paid_at)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_SUCCESS);

    // 锁定 2 → 支付后锁定清零（可售不变：20-2=18）
    $inv = Inventory::where('sku_id', $sku->id)->first();
    expect((int) $inv->stock)->toBe(18)
        ->and((int) $inv->locked_stock)->toBe(0);
});

// PAY-U-05 重复回调幂等
test('重复成功回调幂等跳过', function () {
    [$user, $sku, $order] = createPendingOrder();
    $service = app(PaymentService::class);
    [$payment] = $service->createPayment($order, $user->id, 'wechat');

    $r1 = $service->sandboxNotify($payment->payment_no);
    $r2 = $service->sandboxNotify($payment->payment_no);

    expect($r1['ok'])->toBeTrue()->and($r2['ok'])->toBeTrue();

    // 库存只扣一次
    $inv = Inventory::where('sku_id', $sku->id)->first();
    expect((int) $inv->locked_stock)->toBe(0)
        ->and((int) $inv->stock)->toBe(18)
        ->and(PaymentLog::where('payment_id', $payment->id)->where('event', 'callback')->count())->toBe(2);
});

// PAY-U-06 支付单不存在 / 渠道不匹配
test('不存在的支付单返回失败', function () {
    $service = app(PaymentService::class);
    $result = $service->handleCallback('wechat', [
        'payment_no' => 'PAY-NOPE',
        'channel_trade_no' => 'T',
        'amount' => '1.00',
        'status' => 'success',
        'sign' => 'x',
    ]);

    expect($result['ok'])->toBeFalse();
});

// PAY-U-07 金额一致性：回调金额与支付单金额不一致应拒绝
test('回调金额与支付单金额不一致时拒绝入账', function () {
    [$user, $sku, $order] = createPendingOrder(price: '50.00');
    $service = app(PaymentService::class);
    [$payment] = $service->createPayment($order, $user->id, 'wechat');

    // 用错误金额（0.01）构造合法签名 → 应被金额校验拦截
    $tradeNo = 'FAKE-TRADE';
    $sign = $service->sign($payment->payment_no, $tradeNo, '0.01', 'success');
    $result = $service->handleCallback('wechat', [
        'payment_no' => $payment->payment_no,
        'channel_trade_no' => $tradeNo,
        'amount' => '0.01',
        'status' => 'success',
        'sign' => $sign,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_PENDING)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PENDING_PAYMENT);
});

// PAY-U-08 关闭待支付单
test('closePendingForOrder 关闭订单待支付单', function () {
    [$user, $sku, $order] = createPendingOrder();
    app(PaymentService::class)->createPayment($order, $user->id, 'wechat');

    PaymentService::closePendingForOrder($order->id);

    expect(Payment::where('order_id', $order->id)->value('status'))->toBe(Payment::STATUS_CLOSED);
});

// PAY-U-09 查询：仅本人可见
test('queryByNo 仅返回本人支付单', function () {
    [$user, $sku, $order] = createPendingOrder();
    $service = app(PaymentService::class);
    [$payment] = $service->createPayment($order, $user->id, 'wechat');

    expect($service->queryByNo($payment->payment_no, $user->id)->id)->toBe($payment->id);

    $other = createTestUser('other2');
    $service->queryByNo($payment->payment_no, $other->id);
})->throws(App\Exceptions\BusinessException::class, '支付单不存在');
