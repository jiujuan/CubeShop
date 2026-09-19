<?php

use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Services\Common\CaptchaService;
use App\Services\Wms\FulfillmentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * WMS 履约发货单（WMS 计划 P1 / §4.1）
 *
 * 覆盖：支付成功自动建单（含订单冗余回填）、幂等（一单一单 / 取消后重建）、
 * manual 缺映射落异常、未启用 WMS 的零影响回归、后台列表/详情/取消/重推、
 * 权限隔离、回传发货（唯一入口 OrderService::shipForShipment）与幂等。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true, 'app.url' => 'https://shop.test']);
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 客服：不持有任何 wms.* 权限，用于权限隔离断言
    $cs = SysUser::create([
        'username' => 'cs_ff_'.uniqid(),
        'password' => Hash::make('Cs@123456'),
        'nickname' => '客服',
        'status' => 1,
    ]);
    $cs->assignRole('cs_agent');
    $this->csAuth = ['Authorization' => 'Bearer '.$cs->createToken('cs')->plainTextToken];

    // 买家 + 收货地址 + 可下单 SKU
    $this->user = createTestUser('ffusr');
    $this->userAuth = ['Authorization' => 'Bearer '.$this->user->createToken('ff')->plainTextToken];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $this->userAuth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 20, price: '50.00');
});

/** 建仓并写入 WMS 配置（默认启用 + 自动推送 + same 映射） */
function p1EnableWms(array $attrs = []): array
{
    $warehouse = Warehouse::create(['code' => 'WH_P1_'.uniqid(), 'name' => 'P1 履约仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('p', 26).uniqid(),
    ], $attrs));

    return [$warehouse, $config];
}

/** 走真实下单流程（购物车 → 下单） */
function p1PlaceOrder(): Order
{
    test()->postJson('/api/cart', ['sku_id' => test()->sku->id, 'quantity' => 1], test()->userAuth)->assertOk();
    $data = test()->postJson('/api/orders', ['address_id' => test()->addressId], test()->userAuth)->json('data');

    return Order::where('order_no', $data['order_no'])->firstOrFail();
}

/** 沙箱支付（订单 → 已支付 → 待发货，触发履约链路） */
function p1Pay(Order $order): void
{
    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], test()->userAuth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], test()->userAuth)->assertOk();
}

/** 直接构造发货单（供后台接口用例精确控制状态） */
function p1MakeFulfillment(string $status = FulfillmentOrder::STATUS_PENDING_PUSH, array $attrs = []): FulfillmentOrder
{
    [$warehouse] = p1EnableWms();

    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => test()->user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [
            'contact_name' => '收件人', 'contact_phone' => '13800000000',
            'full_address' => '广东省深圳市南山区科技路 1 号',
        ],
        'warehouse_id' => $warehouse->id,
    ]);

    $fo = FulfillmentOrder::create(array_merge([
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'outbound_no' => 'FO'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'status' => $status,
        'buyer_info' => $order->address_snapshot,
        'extend' => [],
    ], $attrs));

    $fo->items()->create([
        'sku_id' => null,
        'platform_sku_code' => 'SKU-FF',
        'wms_sku_code' => 'W-FF',
        'product_name' => '履约测试商品',
        'qty' => 2,
        'shipped_qty' => 0,
    ]);

    return $fo->load('items');
}

// ==================== 触发建单 ====================

test('TC-FF-001 支付成功后自动创建发货单并派发推送作业', function () {
    Queue::fake();
    [$warehouse] = p1EnableWms();

    $order = p1PlaceOrder();
    p1Pay($order);

    $fo = FulfillmentOrder::where('order_id', $order->id)->first();
    expect($fo)->not->toBeNull()
        ->and($fo->status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH)
        ->and($fo->outbound_no)->toStartWith('FO')
        ->and((int) $fo->warehouse_id)->toBe($warehouse->id)
        ->and($fo->buyer_info['contact_name'])->toBe('收件人')
        ->and($fo->items)->toHaveCount(1)
        // same 映射模式：WMS 货品编码回落平台 sku_code
        ->and($fo->items->first()->wms_sku_code)->toBe($this->sku->sku_code)
        ->and($fo->items->first()->qty)->toBe(1);

    Queue::assertPushed(PushOutboundJob::class, 1);

    // 订单侧冗余回填（F3）
    $order->refresh();
    expect((int) $order->warehouse_id)->toBe($warehouse->id)
        ->and($order->fulfillment_status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH)
        ->and($order->status)->toBe(Order::STATUS_PENDING_SHIP);
});

test('TC-FF-002 一单一发货单：重复建单返回同一张', function () {
    Queue::fake();
    p1EnableWms();

    $order = p1PlaceOrder();
    p1Pay($order);

    $first = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    $again = app(FulfillmentOrderService::class)->createForOrder($order->fresh());

    expect($again->id)->toBe($first->id)
        ->and(FulfillmentOrder::where('order_id', $order->id)->count())->toBe(1);
});

test('TC-FF-003 已取消的发货单不阻塞重建（退款驳回回流后可再次履约）', function () {
    Queue::fake();
    p1EnableWms();

    $order = p1PlaceOrder();
    p1Pay($order);

    $first = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    app(FulfillmentOrderService::class)->cancel($first, '买家取消订单');

    $rebuilt = app(FulfillmentOrderService::class)->createForOrder($order->fresh());

    expect($rebuilt->id)->not->toBe($first->id)
        ->and($rebuilt->status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH)
        ->and(FulfillmentOrder::where('order_id', $order->id)->count())->toBe(2)
        ->and(FulfillmentOrder::where('order_id', $order->id)->where('status', '!=', 'cancelled')->count())->toBe(1);
});

test('TC-FF-004 manual 映射缺货品编码时发货单落异常且写明原因，不派发推送', function () {
    Queue::fake();
    p1EnableWms(['sku_mapping_mode' => 'manual']);

    $order = p1PlaceOrder();
    p1Pay($order);

    $fo = FulfillmentOrder::where('order_id', $order->id)->first();
    expect($fo)->not->toBeNull()
        ->and($fo->status)->toBe(FulfillmentOrder::STATUS_EXCEPTION)
        ->and($fo->exception_reason)->toContain('未配置 WMS 货品编码')
        ->and($fo->items->first()->wms_sku_code)->toBe('');

    Queue::assertNotPushed(PushOutboundJob::class);
});

test('TC-FF-005 未启用 WMS 时支付不生成发货单，订单流程零变化', function () {
    Queue::fake();
    p1EnableWms(['enabled' => false]);

    $order = p1PlaceOrder();
    p1Pay($order);

    expect(FulfillmentOrder::count())->toBe(0)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and($order->fresh()->warehouse_id)->toBeNull()
        ->and($order->fresh()->fulfillment_status)->toBeNull();

    Queue::assertNotPushed(PushOutboundJob::class);
});

test('TC-FF-006 未开启自动推送时只建单不派发作业（等人工推送）', function () {
    Queue::fake();
    p1EnableWms(['auto_push' => false]);

    $order = p1PlaceOrder();
    p1Pay($order);

    $fo = FulfillmentOrder::where('order_id', $order->id)->first();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_CREATED);

    Queue::assertNotPushed(PushOutboundJob::class);
});

// ==================== 后台接口 ====================

test('TC-FF-007 后台列表：支持状态与单号筛选，返回中文状态标签', function () {
    $fo = p1MakeFulfillment();

    $list = $this->getJson('/api/admin/wms/fulfillment-orders?status=pending_push', $this->adminAuth)
        ->assertOk()->json('data');

    expect($list['pagination']['total'])->toBe(1)
        ->and($list['list'][0]['outbound_no'])->toBe($fo->outbound_no)
        ->and($list['list'][0]['status_label'])->toBe('待推送')
        ->and($list['list'][0]['can_push'])->toBeTrue()
        ->and($list['list'][0]['can_cancel'])->toBeTrue()
        ->and($list['list'][0]['warehouse_name'])->toBe('P1 履约仓');

    // 单号模糊匹配
    $this->getJson('/api/admin/wms/fulfillment-orders?outbound_no='.substr($fo->outbound_no, 0, 12), $this->adminAuth)
        ->assertOk()->assertJsonPath('data.pagination.total', 1);

    // 不匹配的状态筛选返回空
    $this->getJson('/api/admin/wms/fulfillment-orders?status=completed', $this->adminAuth)
        ->assertOk()->assertJsonPath('data.pagination.total', 0);
});

test('TC-FF-008 后台详情：返回行项目、买家快照与推送上下文', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PUSH_FAILED, [
        'push_times' => 3,
        'last_push_error' => '连接超时',
    ]);

    $data = $this->getJson("/api/admin/wms/fulfillment-orders/{$fo->id}", $this->adminAuth)
        ->assertOk()->json('data');

    expect($data['outbound_no'])->toBe($fo->outbound_no)
        ->and($data['status'])->toBe('push_failed')
        ->and($data['push_times'])->toBe(3)
        ->and($data['last_push_error'])->toBe('连接超时')
        ->and($data['buyer_info']['contact_name'])->toBe('收件人')
        ->and($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['platform_sku_code'])->toBe('SKU-FF')
        ->and($data['items'][0]['wms_sku_code'])->toBe('W-FF')
        ->and($data['items'][0]['qty'])->toBe(2)
        ->and($data['can_cancel'])->toBeTrue();
});

test('TC-FF-009 后台取消：待推送单可取消并落审计日志', function () {
    $fo = p1MakeFulfillment();

    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/cancel", ['reason' => '买家已取消订单'], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('data.status', FulfillmentOrder::STATUS_CANCELLED);

    $fo->refresh();
    expect($fo->cancelled_at)->not->toBeNull()
        ->and($fo->order->fresh()->fulfillment_status)->toBe(FulfillmentOrder::STATUS_CANCELLED);

    $log = SysOperationLog::where('module', 'wms')->where('action', 'fulfillment_cancelled')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(SysOperationLog::ACTOR_ADMIN)
        ->and($log->admin)->not->toBeNull();
});

test('TC-FF-010 后台取消：已发货单不允许取消（409）', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_SHIPPED);

    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/cancel", ['reason' => '不想要了'], $this->adminAuth)
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);

    expect($fo->fresh()->status)->toBe(FulfillmentOrder::STATUS_SHIPPED);
});

test('TC-FF-011 后台重推：推送失败回到待推送并重新派发作业', function () {
    Queue::fake();
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PUSH_FAILED, ['push_times' => 3, 'last_push_error' => '超时']);

    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/push", [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('data.status', FulfillmentOrder::STATUS_PENDING_PUSH);

    expect($fo->fresh()->push_request_id)->toBeNull();
    Queue::assertPushed(PushOutboundJob::class, 1);
});

test('TC-FF-012 后台重推：已推送的单不允许重推（409）', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PUSHED);

    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/push", [], $this->adminAuth)
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);
});

test('TC-FF-013 权限隔离：无 wms.order.view 的角色看不到发货单', function () {
    p1MakeFulfillment();

    $this->getJson('/api/admin/wms/fulfillment-orders', $this->csAuth)->assertStatus(403);
    $this->getJson('/api/admin/wms/fulfillment-orders/1', $this->csAuth)->assertStatus(403);
});

test('TC-FF-014 权限隔离：无 wms.order.manage 的角色不能重推/取消', function () {
    $fo = p1MakeFulfillment();

    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/push", [], $this->csAuth)->assertStatus(403);
    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/cancel", ['reason' => 'x'], $this->csAuth)->assertStatus(403);

    expect($fo->fresh()->status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH);
});

// ==================== 回传发货 ====================

test('TC-FF-015 回传发货：订单转已发货、落运单与物流记录，发货单同步置已发货', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PUSHED, ['wms_outbound_no' => 'WMS-OUT-1']);

    app(FulfillmentOrderService::class)->markShipped($fo, 'SF', '顺丰速运', 'SF1234567890');

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and($fo->tracking_no)->toBe('SF1234567890')
        ->and($fo->carrier_code)->toBe('SF')
        ->and($fo->shipped_at)->not->toBeNull()
        // 未传实发数量 → 按应发数量计
        ->and($fo->items->first()->shipped_qty)->toBe(2);

    $order = $fo->order->fresh();
    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->tracking_no)->toBe('SF1234567890')
        ->and($order->express_company)->toBe('顺丰速运')
        ->and($order->fulfillment_status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and(Shipping::where('order_id', $order->id)->count())->toBe(1);
});

test('TC-FF-016 回传发货：同一运单号重复回传按幂等成功处理', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PUSHED);

    $service = app(FulfillmentOrderService::class);
    $service->markShipped($fo, 'SF', '顺丰速运', 'SF9999');
    $service->markShipped($fo->fresh(), 'SF', '顺丰速运', 'SF9999');

    expect($fo->fresh()->status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and(Shipping::where('order_id', $fo->order_id)->count())->toBe(1);
});

test('TC-FF-017 回传发货：未推送的单不允许回传（409）', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PENDING_PUSH);

    expect(fn () => app(FulfillmentOrderService::class)->markShipped($fo, 'SF', '顺丰速运', 'SF0001'))
        ->toThrow(App\Exceptions\BusinessException::class);

    expect($fo->fresh()->status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH)
        ->and($fo->order->fresh()->status)->toBe(Order::STATUS_PENDING_SHIP);
});

test('TC-FF-018 回传发货：订单已发货且运单号不一致时拒绝覆盖（409）', function () {
    $fo = p1MakeFulfillment(FulfillmentOrder::STATUS_PUSHED);

    $service = app(FulfillmentOrderService::class);
    $service->markShipped($fo, 'SF', '顺丰速运', 'SF-AAA');

    // 发货单已 shipped，用不同运单号再回传 → 状态与运单号都需人工核对
    expect(fn () => $service->markShipped($fo->fresh(), 'SF', '顺丰速运', 'SF-BBB'))
        ->toThrow(App\Exceptions\BusinessException::class);

    expect($fo->fresh()->tracking_no)->toBe('SF-AAA');
});
