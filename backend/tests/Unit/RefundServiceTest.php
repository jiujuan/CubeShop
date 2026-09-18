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

// RF-U-02 退款金额超过可退余额（首笔退款时即订单实付）被拒绝
test('退款金额超过可退余额被拒绝', function () {
    [$user, $sku, $order] = createPaidOrder();

    app(RefundService::class)->apply($order, $user->id, 'x', '99999.00');
})->throws(App\Exceptions\BusinessException::class, '退款金额超过可退余额');

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

// ---------- 退货退款（WMS 基础，P4 / D3）----------

test('退货退款申请需填写应退明细且数量不超购买', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);

    // 缺明细
    expect(fn () => $service->apply($order, $user->id, 'x', null, ['type' => Refund::TYPE_RETURN_REFUND]))
        ->toThrow(App\Exceptions\BusinessException::class);
    // 超购数量
    expect(fn () => $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 99]],
    ]))->toThrow(App\Exceptions\BusinessException::class);
    // 合法
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    expect($refund->type)->toBe(Refund::TYPE_RETURN_REFUND)
        ->and($refund->return_details[0]['sku_id'])->toBe($sku->id);
});

test('退货退款审核通过停在 approved 等待收货，订单保持退款中（不立即放款）', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    $admin = createTestUser('admin5');
    $refund = $service->process($refund, $admin->id, 'approve');

    expect($refund->status)->toBe(Refund::STATUS_APPROVED)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_WAITING_RETURN)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDING);
});

test('退货确认收货按正品回库存并退款完成', function () {
    [$user, $sku, $order] = createPaidOrder();
    $stockBefore = \App\Models\Inventory::where('sku_id', $sku->id)->value('stock');
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    $admin = createTestUser('admin6');
    $service->process($refund, $admin->id, 'approve');
    $refund = $service->receiveReturn($refund, $admin->id, [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'good']]);

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_RECEIVED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and(\App\Models\Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 1);
});

test('退货残次不回库存', function () {
    [$user, $sku, $order] = createPaidOrder();
    $stockBefore = \App\Models\Inventory::where('sku_id', $sku->id)->value('stock');
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    $admin = createTestUser('admin7');
    $service->process($refund, $admin->id, 'approve');
    $refund = $service->receiveReturn($refund, $admin->id, [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'defective']]);

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and(\App\Models\Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);
});

test('退货实收少于应退记差异但退款仍完成', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    $admin = createTestUser('admin8');
    $service->process($refund, $admin->id, 'approve');
    $refund = $service->receiveReturn($refund, $admin->id, [['sku_id' => $sku->id, 'quantity' => 0, 'condition' => 'good']], '用户称未寄到');

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_exception_reason)->not->toBeNull();
});

test('仅退款类型不可确认收货', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null);
    $admin = createTestUser('admin9');
    $service->process($refund, $admin->id, 'approve');

    expect(fn () => $service->receiveReturn($refund, $admin->id, [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'good']]))
        ->toThrow(App\Exceptions\BusinessException::class);
});

test('退货确认收货幂等不重复回库存', function () {
    [$user, $sku, $order] = createPaidOrder();
    $stockBefore = \App\Models\Inventory::where('sku_id', $sku->id)->value('stock');
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    $admin = createTestUser('admin10');
    $service->process($refund, $admin->id, 'approve');
    $refund = $service->receiveReturn($refund, $admin->id, [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'good']]);
    $again = $service->receiveReturn($refund->fresh(), $admin->id, [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'good']]);

    expect($again->status)->toBe(Refund::STATUS_SUCCESS)
        ->and(\App\Models\Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 1);
});
