<?php

use App\Models\Order;
use App\Models\OrderLog;
use App\Services\Common\CaptchaService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-001 / T-002 / T-003：订单生命周期闭环
 *
 * 覆盖：状态流水落库、用户确认收货、自动确认收货 Command。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    // 管理员
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 买家
    $cap2 = app(CaptchaService::class)->generate();
    $this->userToken = $this->postJson('/api/auth/register', [
        'username' => 'lifecycle'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token');
    $this->userAuth = ['Authorization' => 'Bearer '.$this->userToken];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $this->userAuth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '50.00');
});

/** 下单 → 支付 → 发货，返回订单 ID */
function makeShippedOrder($test, string $skuPrice = '50.00'): int
{
    $test->postJson('/api/cart', ['sku_id' => $test->sku->id, 'quantity' => 1], $test->userAuth);
    $order = $test->postJson('/api/orders', ['address_id' => $test->addressId], $test->userAuth)->json('data');

    $pay = $test->postJson('/api/payments', [
        'order_no' => $order['order_no'], 'channel' => 'wechat',
    ], $test->userAuth)->json('data');
    $payNo = $pay['payment_no'] ?? ($pay['pay_params']['payment_no'] ?? null);
    $test->postJson("/api/payments/sandbox/{$payNo}", [], $test->userAuth);

    $test->postJson("/api/admin/orders/{$order['order_id']}/ship", [
        'remark' => '已发出',
    ], $test->adminAuth)->assertStatus(200);

    return $order['order_id'];
}

// T-001：下单后即产生创建流水
test('TC-LIFE-001 下单产生创建流水且操作人为 user', function () {
    $this->postJson('/api/cart', ['sku_id' => $this->sku->id, 'quantity' => 1], $this->userAuth);
    $order = $this->postJson('/api/orders', ['address_id' => $this->addressId], $this->userAuth)->json('data');

    $logs = OrderLog::where('order_id', $order['order_id'])->orderBy('id')->get();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->from_status)->toBeNull()
        ->and($logs[0]->to_status)->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($logs[0]->operator_type)->toBe(OrderLog::OPERATOR_USER)
        ->and($logs[0]->operator_id)->not->toBeNull();
});

// T-001：完整链路流水顺序与操作人类型
test('TC-LIFE-002 支付与发货各写一条流水且顺序正确', function () {
    $orderId = makeShippedOrder($this);

    $logs = OrderLog::where('order_id', $orderId)->orderBy('id')->get();

    expect($logs->pluck('to_status')->all())
        ->toBe([Order::STATUS_PENDING_PAYMENT, Order::STATUS_PAID, Order::STATUS_SHIPPED])
        ->and($logs->pluck('from_status')->all())
        ->toBe([null, Order::STATUS_PENDING_PAYMENT, Order::STATUS_PAID]);

    // 支付为系统（渠道回调），发货为 admin
    expect($logs[1]->operator_type)->toBe(OrderLog::OPERATOR_SYSTEM)
        ->and($logs[2]->operator_type)->toBe(OrderLog::OPERATOR_ADMIN);
});

// T-002：确认收货成功
test('TC-LIFE-003 已发货订单确认收货成功', function () {
    $orderId = makeShippedOrder($this);

    $resp = $this->postJson("/api/orders/{$orderId}/confirm", [], $this->userAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.status'))->toBe(Order::STATUS_COMPLETED)
        ->and($resp->json('data.completed_at'))->not->toBeNull();

    $order = Order::find($orderId);
    expect($order->completed_at)->not->toBeNull()
        ->and($order->auto_completed)->toBeFalse();

    // 流水：完成节点 operator_type = user
    $last = OrderLog::where('order_id', $orderId)->orderByDesc('id')->first();
    expect($last->to_status)->toBe(Order::STATUS_COMPLETED)
        ->and($last->operator_type)->toBe(OrderLog::OPERATOR_USER)
        ->and($last->from_status)->toBe(Order::STATUS_SHIPPED);
});

// T-002：重复确认收货幂等
test('TC-LIFE-004 重复确认收货幂等且只产生一条完成流水', function () {
    $orderId = makeShippedOrder($this);

    $this->postJson("/api/orders/{$orderId}/confirm", [], $this->userAuth);
    $second = $this->postJson("/api/orders/{$orderId}/confirm", [], $this->userAuth);

    expect($second->json('code'))->toBe(0)
        ->and($second->json('data.status'))->toBe(Order::STATUS_COMPLETED);

    expect(OrderLog::where('order_id', $orderId)->where('to_status', Order::STATUS_COMPLETED)->count())->toBe(1);
});

// T-002：未发货订单确认收货被拒
test('TC-LIFE-005 未发货订单确认收货被状态机拒绝', function () {
    $this->postJson('/api/cart', ['sku_id' => $this->sku->id, 'quantity' => 1], $this->userAuth);
    $order = $this->postJson('/api/orders', ['address_id' => $this->addressId], $this->userAuth)->json('data');

    $resp = $this->postJson("/api/orders/{$order['order_id']}/confirm", [], $this->userAuth);

    expect($resp->json('code'))->toBe(40009);
});

// T-002：他人订单 403/404
test('TC-LIFE-006 他人订单不可确认收货', function () {
    $orderId = makeShippedOrder($this);

    $cap = app(CaptchaService::class)->generate();
    $otherAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'other'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];

    $this->postJson("/api/orders/{$orderId}/confirm", [], $otherAuth)->assertStatus(404);
});

// T-002：未登录 401
test('TC-LIFE-007 未登录确认收货返回 401', function () {
    $orderId = makeShippedOrder($this);
    $this->postJson("/api/orders/{$orderId}/confirm")->assertStatus(401);
});

// T-002：订单不存在 404
test('TC-LIFE-008 确认不存在的订单返回 404', function () {
    $this->postJson('/api/orders/99999999/confirm', [], $this->userAuth)->assertStatus(404);
});

// T-003：超期自动确认收货
test('TC-LIFE-009 超过配置天数自动确认收货且标记来源', function () {
    $orderId = makeShippedOrder($this);

    // 把发货时间改为 8 天前
    Order::whereKey($orderId)->update(['shipped_at' => now()->subDays(8)]);

    $this->artisan('orders:auto-complete')->assertExitCode(0);

    $order = Order::find($orderId);
    expect($order->status)->toBe(Order::STATUS_COMPLETED)
        ->and($order->auto_completed)->toBeTrue()
        ->and($order->completed_at)->not->toBeNull();

    $last = OrderLog::where('order_id', $orderId)->orderByDesc('id')->first();
    expect($last->to_status)->toBe(Order::STATUS_COMPLETED)
        ->and($last->operator_type)->toBe(OrderLog::OPERATOR_SYSTEM)
        ->and($last->remark)->toContain('自动确认');
});

// T-003：未超期不处理
test('TC-LIFE-010 未超期订单不被自动确认', function () {
    $orderId = makeShippedOrder($this);
    Order::whereKey($orderId)->update(['shipped_at' => now()->subDays(3)]);

    $this->artisan('orders:auto-complete')->assertExitCode(0);

    expect(Order::find($orderId)->status)->toBe(Order::STATUS_SHIPPED);
});

// T-003：时间边界（刚好 7 天 / 6天23小时 / 8天）
test('TC-LIFE-011 自动确认收货时间边界判定', function () {
    $exact = makeShippedOrder($this);
    Order::whereKey($exact)->update(['shipped_at' => now()->subDays(7)]);

    $notYet = makeShippedOrder($this);
    Order::whereKey($notYet)->update(['shipped_at' => now()->subDays(6)->subHours(23)]);

    $this->artisan('orders:auto-complete')->assertExitCode(0);

    expect(Order::find($exact)->status)->toBe(Order::STATUS_COMPLETED)
        ->and(Order::find($notYet)->status)->toBe(Order::STATUS_SHIPPED);
});

// T-003：Command 幂等，重复执行处理数为 0
test('TC-LIFE-012 自动确认收货重复执行幂等', function () {
    $orderId = makeShippedOrder($this);
    Order::whereKey($orderId)->update(['shipped_at' => now()->subDays(10)]);

    $this->artisan('orders:auto-complete')->assertExitCode(0);
    $countAfterFirst = OrderLog::where('order_id', $orderId)->count();

    $this->artisan('orders:auto-complete')
        ->expectsOutputToContain('没有需要自动确认收货的订单')
        ->assertExitCode(0);

    expect(OrderLog::where('order_id', $orderId)->count())->toBe($countAfterFirst);
});

// T-003：dry-run 只统计不执行
test('TC-LIFE-013 dry-run 仅统计不改变状态', function () {
    $orderId = makeShippedOrder($this);
    Order::whereKey($orderId)->update(['shipped_at' => now()->subDays(9)]);

    $this->artisan('orders:auto-complete --dry-run')
        ->expectsOutputToContain('待自动确认收货订单')
        ->assertExitCode(0);

    expect(Order::find($orderId)->status)->toBe(Order::STATUS_SHIPPED);
});

// T-002 + T-003：手动确认与自动确认不冲突
test('TC-LIFE-014 手动确认后自动任务不再处理', function () {
    $orderId = makeShippedOrder($this);
    Order::whereKey($orderId)->update(['shipped_at' => now()->subDays(20)]);

    $this->postJson("/api/orders/{$orderId}/confirm", [], $this->userAuth)->assertStatus(200);
    $this->artisan('orders:auto-complete')->assertExitCode(0);

    $order = Order::find($orderId);
    expect($order->status)->toBe(Order::STATUS_COMPLETED)
        ->and($order->auto_completed)->toBeFalse()
        ->and(OrderLog::where('order_id', $orderId)->where('to_status', Order::STATUS_COMPLETED)->count())->toBe(1);
});

// T-001：订单详情返回 logs（供前端时间轴）
test('TC-LIFE-015 订单详情返回状态流水', function () {
    $orderId = makeShippedOrder($this);

    $detail = $this->getJson("/api/orders/{$orderId}", $this->userAuth)->json('data');

    expect($detail)->toHaveKey('logs')
        ->and($detail['logs'])->toHaveCount(3)
        ->and($detail['logs'][2]['to_status'])->toBe(Order::STATUS_SHIPPED)
        ->and($detail['logs'][2]['to_status_label'])->toBe('已发货');
});

// T-001：回填命令幂等
test('TC-LIFE-016 历史流水回填命令可用且幂等', function () {
    $orderId = makeShippedOrder($this);
    OrderLog::where('order_id', $orderId)->delete(); // 模拟历史订单无流水

    $this->artisan('orders:backfill-logs')->assertExitCode(0);
    $count = OrderLog::where('order_id', $orderId)->count();
    expect($count)->toBe(3);

    $this->artisan('orders:backfill-logs')->assertExitCode(0);
    expect(OrderLog::where('order_id', $orderId)->count())->toBe($count);
});

// T-002：状态机允许 shipped→completed，拒绝 pending_payment→completed
test('TC-LIFE-017 状态机流转规则正确', function () {
    $order = new Order(['status' => Order::STATUS_SHIPPED]);
    expect($order->canTransitTo(Order::STATUS_COMPLETED))->toBeTrue();

    $order2 = new Order(['status' => Order::STATUS_PENDING_PAYMENT]);
    expect($order2->canTransitTo(Order::STATUS_COMPLETED))->toBeFalse();
});

// T-003：配置可覆盖自动确认天数
test('TC-LIFE-018 自动确认天数可配置', function () {
    $orderId = makeShippedOrder($this);
    Order::whereKey($orderId)->update(['shipped_at' => now()->subDays(4)]);

    // 默认 7 天：不处理
    $this->artisan('orders:auto-complete')->assertExitCode(0);
    expect(Order::find($orderId)->status)->toBe(Order::STATUS_SHIPPED);

    // 配置改为 3 天：处理
    app(\App\Services\Common\ConfigService::class)->set('order.auto_complete_days', '3');
    $this->artisan('orders:auto-complete')->assertExitCode(0);
    expect(Order::find($orderId)->status)->toBe(Order::STATUS_COMPLETED);
});
