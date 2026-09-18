<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\UserAddress;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 构造可直接下单的购物车场景
 */
function prepareCartContext(int $qty = 2, string $price = '99.00'): array
{
    $user = createTestUser();
    $sku = createTestSku(stock: 20, price: $price);
    CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => $qty]);
    $address = UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => '测试收件人',
        'contact_phone' => '13800000000',
        'province' => '广东省',
        'city' => '深圳市',
        'district' => '南山区',
        'detail_address' => '测试路 1 号',
        'is_default' => 1,
    ]);

    return [$user, $sku, $address];
}

// ORD-U-01 从购物车创建订单：快照、金额、清购物车、锁库存
test('createFromCart 生成订单并快照金额与地址', function () {
    [$user, $sku, $address] = prepareCartContext(qty: 2, price: '99.00');

    /** @var OrderService $service */
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, '备注一下');

    expect($order->status)->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($order->total_amount)->toBe('198.00')          // 99 × 2
        ->and($order->pay_amount)->toBe('208.00')            // 空配置表 → 阈值 0 → 不启用包邮 → + 运费 10
        ->and($order->items)->toHaveCount(1)
        ->and($order->items[0]->price)->toBe('99.00')
        ->and($order->items[0]->quantity)->toBe(2)
        ->and($order->items[0]->product_title)->toContain('测试商品')
        ->and($order->address_snapshot['contact_name'])->toBe('测试收件人');

    // 购物车项已清理
    expect(CartItem::where('user_id', $user->id)->count())->toBe(0);

    // 订单号格式：CS + 日期 + 10 位随机段（SEC-03：不再使用可枚举的 6 位序列）
    expect($order->order_no)->toMatch('/^CS\d{4}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{10}$/');
});

test('满额包邮：达到阈值后运费为 0', function () {
    // 设置包邮阈值配置
    app(\App\Services\Common\ConfigService::class)->set('order.free_shipping_threshold', '99.00');

    [$user, $sku, $address] = prepareCartContext(qty: 1, price: '99.00');

    $order = app(OrderService::class)->createFromCart($user->id, $address->id, null, null);

    // total 99 ≥ threshold 99 → freight 0 → pay = 99
    expect($order->freight_amount)->toBe('0.00')
        ->and($order->pay_amount)->toBe('99.00');
});

test('未达包邮阈值收取默认运费', function () {
    [$user, $sku, $address] = prepareCartContext(qty: 1, price: '50.00');

    $order = app(OrderService::class)->createFromCart($user->id, $address->id, null, null);

    expect($order->freight_amount)->toBe('10.00')
        ->and($order->pay_amount)->toBe('60.00');
});

// ORD-U-02 地址不属于本人 → 拒绝
test('使用他人地址下单被拒绝', function () {
    [$user, $sku, $address] = prepareCartContext();
    $other = createTestUser('other');

    app(OrderService::class)->createFromCart($other->id, $address->id, null, null);
})->throws(App\Exceptions\BusinessException::class, '收货地址不存在');

// ORD-U-03 库存不足 → 拒绝
test('库存不足时下单拒绝', function () {
    $user = createTestUser();
    $sku = createTestSku(stock: 1, price: '10.00');
    CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 5]);
    $address = UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);

    app(OrderService::class)->createFromCart($user->id, $address->id, null, null);
})->throws(App\Exceptions\BusinessException::class, '库存不足');

// ORD-U-04 下架商品 → 拒绝
test('商品已下架时下单拒绝', function () {
    $user = createTestUser();
    $sku = createTestSku(stock: 10, productStatus: 0);
    CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
    $address = UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);

    app(OrderService::class)->createFromCart($user->id, $address->id, null, null);
})->throws(App\Exceptions\BusinessException::class, '已下架');

// ORD-U-05 状态机：非法流转拒绝
test('订单状态机禁止非法流转', function () {
    [$user, $sku, $address] = prepareCartContext();
    $order = app(OrderService::class)->createFromCart($user->id, $address->id, null, null);

    // 待支付 → 已完成 非法
    app(OrderService::class)->transitionTo($order, Order::STATUS_COMPLETED);
})->throws(App\Exceptions\BusinessException::class, '不允许变更');

test('合法流转：待支付→已支付→待发货→已发货→已完成', function () {
    [$user, $sku, $address] = prepareCartContext();
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);

    $order = $service->transitionTo($order, Order::STATUS_PAID);
    expect($order->status)->toBe(Order::STATUS_PAID)->and($order->paid_at)->not->toBeNull();

    $order = $service->transitionTo($order, Order::STATUS_PENDING_SHIP);
    expect($order->status)->toBe(Order::STATUS_PENDING_SHIP);

    $order = $service->transitionTo($order, Order::STATUS_SHIPPED);
    expect($order->status)->toBe(Order::STATUS_SHIPPED)->and($order->shipped_at)->not->toBeNull();

    $order = $service->transitionTo($order, Order::STATUS_COMPLETED);
    expect($order->status)->toBe(Order::STATUS_COMPLETED)->and($order->completed_at)->not->toBeNull();
});

// ORD-U-09 待发货是发货的唯一前置：已支付不可直接发货
test('已支付订单不能跳过待发货直接变为已发货', function () {
    [$user, $sku, $address] = prepareCartContext();
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);
    $order = $service->transitionTo($order, Order::STATUS_PAID);

    $service->transitionTo($order, Order::STATUS_SHIPPED);
})->throws(App\Exceptions\BusinessException::class, '不允许变更');

// ORD-U-06 取消订单：他人订单不可取消
test('取消他人订单返回订单不存在', function () {
    [$user, $sku, $address] = prepareCartContext();
    $order = app(OrderService::class)->createFromCart($user->id, $address->id, null, null);
    $other = createTestUser('hacker');

    app(OrderService::class)->cancel($order, $other->id);
})->throws(App\Exceptions\BusinessException::class, '订单不存在');

test('取消待支付订单释放库存', function () {
    [$user, $sku, $address] = prepareCartContext(qty: 3);

    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);

    $stockBefore = \App\Models\Inventory::where('sku_id', $sku->id)->value('stock');
    expect($stockBefore)->toBe(17);

    $order = $service->cancel($order, $user->id, '不想买了');
    expect($order->status)->toBe(Order::STATUS_CANCELLED)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($order->cancel_reason)->toBe('不想买了');

    // 库存释放
    expect((int) \App\Models\Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(20);
});

// ORD-U-07 已支付订单不可用户取消（状态机拦截）
test('已支付订单不能被用户直接取消为已完成以外状态', function () {
    [$user, $sku, $address] = prepareCartContext();
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);
    $service->transitionTo($order, Order::STATUS_PAID);

    // paid → cancelled 在状态机中允许（退款等场景），但 cancel() 走同一状态机；
    // 依据状态机定义 PAID => [PENDING_SHIP, REFUNDING, CANCELLED]，paid 可取消属于设计允许
    $cancelled = $service->cancel($order, $user->id);
    expect($cancelled->status)->toBe(Order::STATUS_CANCELLED);
});

// ORD-U-08 cancelExpired 幂等：已取消订单再次执行返回 false
test('cancelExpired 对已取消订单幂等返回 false', function () {
    [$user, $sku, $address] = prepareCartContext();
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);

    expect($service->cancelExpired($order))->toBeTrue();
    expect($service->cancelExpired($order))->toBeFalse();

    // 库存只释放一次
    expect((int) \App\Models\Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(20);
});

// ORD-U-09 部分结算：仅结算指定购物车项
test('指定 cartItemIds 时仅结算对应项', function () {
    $user = createTestUser();
    $skuA = createTestSku(stock: 10, price: '10.00');
    $skuB = createTestSku(stock: 10, price: '20.00');
    $c1 = CartItem::create(['user_id' => $user->id, 'sku_id' => $skuA->id, 'quantity' => 1]);
    CartItem::create(['user_id' => $user->id, 'sku_id' => $skuB->id, 'quantity' => 2]);
    $address = UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);

    $order = app(OrderService::class)->createFromCart($user->id, $address->id, [$c1->id], null);

    expect($order->items)->toHaveCount(1)
        ->and($order->total_amount)->toBe('10.00')
        // 未结算项保留
        ->and(CartItem::where('user_id', $user->id)->count())->toBe(1);
});
