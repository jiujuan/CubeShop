<?php

use App\Models\Order;
use App\Models\Refund;
use App\Models\UserAddress;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPaidOrder(string $price = '100.00'): array
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

    return [$user, $sku, $order];
}

// RF-U-01 申请退款：paid → refunding，生成退款单
test('申请退款后订单进入退款中并生成待审退款单', function () {
    [$user, $sku, $order] = createPaidOrder();

    $refund = app(RefundService::class)->apply($order, $user->id, '不想要了', null);

    expect($refund->status)->toBe(Refund::STATUS_PENDING)
        ->and((string) $refund->amount)->toBe($order->pay_amount)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDING)
        ->and($refund->refund_no)->toStartWith('RF');
});

// RF-U-02 退款金额不能超过实付
test('退款金额超过实付被拒绝', function () {
    [$user, $sku, $order] = createPaidOrder();

    app(RefundService::class)->apply($order, $user->id, 'x', '99999.00');
})->throws(App\Exceptions\BusinessException::class, '退款金额不能超过实付金额');

test('退款金额小于等于 0 被拒绝', function () {
    [$user, $sku, $order] = createPaidOrder();

    app(RefundService::class)->apply($order, $user->id, 'x', '0.00');
})->throws(App\Exceptions\BusinessException::class, '退款金额必须大于 0');

// RF-U-03 防重复申请
test('同一订单存在未完结退款时拒绝再次申请', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $service->apply($order, $user->id, '第一次', null);

    $order = $order->fresh();
    $service->apply($order, $user->id, '第二次', null);
})->throws(App\Exceptions\BusinessException::class, '请勿重复申请');

// RF-U-04 待支付订单不能申请退款（状态机拦截）
test('待支付订单申请退款被状态机拒绝', function () {
    [$user, $sku] = [null, null];
    $user = createTestUser();
    $sku = createTestSku(stock: 5, price: '10.00');
    \App\Models\CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
    $address = UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);
    $order = app(OrderService::class)->createFromCart($user->id, $address->id, null, null);

    app(RefundService::class)->apply($order, $user->id, 'x', null);
})->throws(App\Exceptions\BusinessException::class, '订单当前状态不支持申请退款');

// RF-U-05 他人订单不可申请
test('非本人订单申请退款返回订单不存在', function () {
    [$user, $sku, $order] = createPaidOrder();
    $other = createTestUser('rf-other');

    app(RefundService::class)->apply($order, $other->id, 'x', null);
})->throws(App\Exceptions\BusinessException::class, '订单不存在');

// RF-U-06 审核通过：refund → refunded
test('审核通过后退款成功且订单变为已退款', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null);

    $admin = createTestUser('admin1');
    $refund = $service->process($refund, $admin->id, 'approve', '同意');

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->processed_by)->toBe($admin->id)
        ->and($refund->processed_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
});

// RF-U-07 审核拒绝：订单回到已支付
test('审核拒绝后退款单拒绝且订单回到已支付', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null);

    $admin = createTestUser('admin2');
    $refund = $service->process($refund, $admin->id, 'reject', '凭证不足');

    expect($refund->status)->toBe(Refund::STATUS_REJECTED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

// RF-U-08 重复审核拒绝
test('已处理的退款单不能重复审核', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null);
    $admin = createTestUser('admin3');
    $service->process($refund, $admin->id, 'approve');

    $service->process($refund, $admin->id, 'reject');
})->throws(App\Exceptions\BusinessException::class, '请勿重复操作');

// RF-U-09 非法 action
test('非法审核操作被拒绝', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null);
    $admin = createTestUser('admin4');

    $service->process($refund, $admin->id, 'destroy');
})->throws(App\Exceptions\BusinessException::class, '非法的审核操作');
