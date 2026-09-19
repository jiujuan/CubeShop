<?php

use App\Events\OrderShipped;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * 既有发货链路回归（WMS 计划 P1 / Step 8「回归 ≥3」）
 *
 * P1 给订单接入点加了 `OrderAcceptedForShipment` 事件与新监听器。本组用例锁定：
 * **WMS 未启用时，既有下游发货链路（支付自动流转、后台单笔发货、通知）零变化**；
 * WMS 启用时，既有人工发货入口也不被履约单阻塞。
 *
 * ⚠️ 已知边界（P1 范围外，留待 P4+）：管理员若绕过 WMS 走 `POST /admin/orders/{id}/ship`
 * 人工发货，既有履约单不会同步为「已发货」——`markShipped()` 只服务 WMS 回传入口。
 * 本组用例据实断言该现状，不做假设。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 买家 + 待发货订单（支付成功自动流转 pending_ship） */
function osxBuyer(): array
{
    $user = createTestUser('osx'.uniqid());

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('osx')->plainTextToken]];
}

function osxPendingShipOrder(array $buyer): Order
{
    $test = test();
    $addr = $test->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $buyer['auth'])->json('data');

    $sku = createTestSku(stock: 10, price: '50.00');
    $test->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $buyer['auth'])->assertOk();
    $order = $test->postJson('/api/orders', ['address_id' => $addr['id'] ?? $addr], $buyer['auth'])->json('data');

    $pay = $test->postJson('/api/payments', ['order_no' => $order['order_no'], 'channel' => 'wechat'], $buyer['auth'])->json('data');
    $test->postJson('/api/payments/sandbox/'.($pay['payment_no'] ?? $pay['pay_params']['payment_no']), [], $buyer['auth'])->assertOk();

    return Order::where('order_no', $order['order_no'])->firstOrFail();
}

/** 启用 WMS（默认自动推送 + same 映射），返回仓库 */
function osxEnableWms(array $attrs = []): Warehouse
{
    $warehouse = Warehouse::create(['code' => 'WH_OSX_'.uniqid(), 'name' => '回归仓', 'status' => 1]);

    WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('q', 26).uniqid(),
    ], $attrs));

    return $warehouse;
}

test('TC-OSX-001 WMS 未启用：后台单笔发货链路与通知完全不变', function () {
    Event::fake([OrderShipped::class]);

    ['user' => $user, 'auth' => $auth] = osxBuyer();
    $order = osxPendingShipOrder(compact('user', 'auth'));

    expect($order->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and(FulfillmentOrder::count())->toBe(0); // 未启用 WMS，不建履约单

    $this->postJson("/api/admin/orders/{$order->id}/ship", [
        'express_company_code' => 'SF',
        'tracking_no' => 'SFOSX00000001',
        'remark' => '当日达',
    ], $this->adminAuth)->assertOk();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->tracking_no)->toBe('SFOSX00000001')
        ->and($order->express_company)->toBe('顺丰速运')
        ->and(Shipping::where('order_id', $order->id)->where('tracking_no', 'SFOSX00000001')->exists())->toBeTrue()
        ->and(FulfillmentOrder::count())->toBe(0);

    Event::assertDispatched(OrderShipped::class);
});

test('TC-OSX-002 WMS 未启用：支付成功不产生履约单，订单照常进入待发货', function () {
    ['user' => $user, 'auth' => $auth] = osxBuyer();
    $order = osxPendingShipOrder(compact('user', 'auth'));

    expect($order->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and(FulfillmentOrder::count())->toBe(0)
        ->and($order->fulfillment_status)->toBeNull();
});

test('TC-OSX-003 WMS 已启用：既有人工发货入口仍可用，不被履约单阻塞', function () {
    $warehouse = osxEnableWms();

    ['user' => $user, 'auth' => $auth] = osxBuyer();
    $order = osxPendingShipOrder(compact('user', 'auth'));

    // 支付成功已自动建单（auto_push 会派发作业，测试环境 sync 队列下 Mock 推送成功）
    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    expect($fo->warehouse_id)->toBe($warehouse->id);

    $this->postJson("/api/admin/orders/{$order->id}/ship", [
        'express_company_code' => 'SF',
        'tracking_no' => 'SFOSX00000002',
    ], $this->adminAuth)->assertOk();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and(Shipping::where('order_id', $order->id)->count())->toBe(1)
        // 现状记录：人工绕过 WMS 发货不会把履约单置为已发货（P4+ 再补联动）
        ->and($fo->fresh()->status)->not->toBe(FulfillmentOrder::STATUS_SHIPPED);
});

test('TC-OSX-004 WMS 未启用：重复发货仍被既有规则拒绝（运单号唯一）', function () {
    ['user' => $user, 'auth' => $auth] = osxBuyer();
    $order = osxPendingShipOrder(compact('user', 'auth'));

    $this->postJson("/api/admin/orders/{$order->id}/ship", [
        'express_company_code' => 'SF', 'tracking_no' => 'SFOSX00000003',
    ], $this->adminAuth)->assertOk();

    $this->postJson("/api/admin/orders/{$order->id}/ship", [
        'express_company_code' => 'SF', 'tracking_no' => 'SFOSX00000004',
    ], $this->adminAuth)->assertJsonPath('code', 40009);

    expect(Shipping::where('order_id', $order->id)->count())->toBe(1);
});
