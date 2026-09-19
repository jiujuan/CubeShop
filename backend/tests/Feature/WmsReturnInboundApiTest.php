<?php

use App\Jobs\Wms\ProcessWmsCallbackJob;
use App\Jobs\Wms\PushReturnInboundJob;
use App\Models\Inventory;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnInboundOrder;
use App\Models\SysOperationLog;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Services\Common\CaptchaService;
use App\Services\Wms\Adapters\Cainiao\Signature;
use App\Services\Wms\Callback\CallbackDeduplicator;
use App\Services\Wms\Callback\CallbackDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * WMS 退货入库闭环（WMS 计划 P4 / §4.1 Feature 测试）
 *
 * 覆盖：审核通过自动建单（auto_push_return 两态）、仅退款强回归（无入库单+即时放款）、
 * 缺映射异常、后台 list/show/push/cancel/manual-received 与权限、推送失败重推、
 * returnorder.confirm 全链路（正品/少件/实收0/超收/残次/重复回传幂等）、
 * 单据不存在告警。
 */

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    Cache::flush();
    // 全局 Fake 队列：既断言入队，也避免同步驱动内联执行推送作业（WMS 外呼）
    Queue::fake();

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

// ==================== 数据准备 ====================

function p4Buyer(): array
{
    $user = createTestUser('p4b');

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('p4b')->plainTextToken]];
}

function p4Address(array $auth): int
{
    $addr = test()->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');

    return $addr['id'] ?? $addr;
}

/** 下单 + 沙箱支付，返回 Order 模型 */
function p4Order(array $auth, int $skuId, int $qty = 2, ?int $warehouseId = null): Order
{
    test()->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->assertOk();
    $addressId = p4Address($auth);
    $data = test()->postJson('/api/orders', ['address_id' => $addressId], $auth)->json('data');
    $order = Order::find(oid($data['order_id']));

    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();

    if ($warehouseId) {
        $order->forceFill(['warehouse_id' => $warehouseId])->save();
    }

    return $order->fresh();
}

/** 建（仓+配置）并返回 config；auto_push_return 默认 true */
function p4WmsConfig(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_P4_'.uniqid(), 'name' => '退货闭环仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'TEST_KEY',
        'customer_id' => 'CUBE_OWNER',
        'api_env' => 'sandbox',
        'warehouse_code' => 'CN-WH',
        'remark' => '',
        'callback_token' => 'rtoken'.bin2hex(random_bytes(12)),
    ], $attrs));

    $config->app_secret = 'TEST_SECRET';
    $config->save();

    return $config->refresh();
}

/** 买家发起退货退款申请（默认全量明细），返回 Refund */
function p4ApplyReturn(array $auth, Order $order, int $skuId, int $qty = 2): Refund
{
    test()->postJson("/api/orders/{$order->id}/refund", [
        'reason' => '七天无理由',
        'type' => 'return_refund',
        'return_details' => [
            ['sku_id' => $skuId, 'quantity' => $qty, 'product_title' => '测试商品', 'sku_specs' => []],
        ],
    ], $auth)->assertOk();

    return Refund::where('order_id', $order->id)->latest('id')->first();
}

/** 已签名回调 POST（sign 放 query，与奇门同构，同 P3 helper 口径） */
function p4Post(array $payload, string $secret = 'TEST_SECRET'): array
{
    $raw = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $scalars = array_filter($payload, 'is_scalar');
    $sign = app(Signature::class)->sign($scalars, $secret, $raw, 'md5');

    $url = '/api/wms/callback/cainiao?'.http_build_query(['sign' => $sign, 'sign_method' => 'md5']);

    return test()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
        ->assertOk()->json();
}

/** 构造 returnorder.confirm 报文 */
function p4ReturnConfirmPayload(ReturnInboundOrder $rio, string $itemCode, int $qty, string $type = 'ZP'): array
{
    return [
        'method' => 'taobao.qimen.returnorder.confirm',
        'timestamp' => '2026-09-20 15:00:00',
        'app_key' => 'TEST_KEY',
        'v' => '2.0',
        'sign_method' => 'md5',
        'returnOrder' => [
            'returnOrderCode' => $rio->inbound_no,
            'returnOrderId' => 'CN-RET-1',
            'orderConfirmTime' => '2026-09-20 15:00:00',
            'orderLines' => ['orderLine' => [
                ['itemCode' => $itemCode, 'actualQty' => $qty, 'inventoryType' => $type],
            ]],
        ],
    ];
}

/** 驱动 Fake 队列里全部已入队的回调 Job */
function p4RunCallbackJobs(): void
{
    Queue::assertPushed(ProcessWmsCallbackJob::class, function ($j) {
        $j->handle(app(CallbackDispatcher::class), app(CallbackDeduplicator::class));

        return true;
    });
}

/** 驱动全部已入队的退货推送 Job（直连，不走 HTTP） */
function p4RunPushJobs(): void
{
    Queue::assertPushed(PushReturnInboundJob::class, function ($j) {
        try {
            $j->handle();
        } catch (RuntimeException) {
            // 可重试失败由断言端按状态验证
        }

        return true;
    });
}

// ==================== 建单链路 ====================

test('TC-RI-001 退货退款审核通过（auto）→ 生成 pending_push 入库单，退款停 approved 订单 refunding', function () {
    Queue::fake();
    $config = p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, $config->warehouse_id);
    $refund = p4ApplyReturn($auth, $order, $sku->id);

    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $rio = ReturnInboundOrder::where('refund_id', $refund->id)->first();
    expect($rio)->not->toBeNull()
        ->and($rio->status)->toBe(ReturnInboundOrder::STATUS_PENDING_PUSH)
        ->and($rio->inbound_no)->toStartWith('RI')
        ->and($rio->refund_no)->toBe($refund->refund_no)
        ->and($rio->items()->count())->toBe(1)
        ->and((int) $rio->items()->first()->qty)->toBe(2)
        ->and((string) $rio->items()->first()->wms_sku_code)->toBe((string) $sku->sku_code);

    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_APPROVED)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_WAITING_RETURN)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDING)
        // 未收货绝不放款、未回库存
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);

    Queue::assertPushed(PushReturnInboundJob::class, 1);
});

test('TC-RI-002 仅退款审核通过（强回归）：无入库单、立即退款成功、订单 refunded', function () {
    Queue::fake();
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);

    test()->postJson("/api/orders/{$order->id}/refund", ['reason' => '不想要了'], $auth)->assertOk();
    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $refund->refresh();
    expect($refund->type)->toBe(Refund::TYPE_REFUND)
        ->and($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and(ReturnInboundOrder::count())->toBe(0);
});

test('TC-RI-003 auto_push_return=false → 入库单 created，后台 push 重新入队', function () {
    Queue::fake();
    p4WmsConfig(['auto_push_return' => false]);
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 1, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 1);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $rio = ReturnInboundOrder::first();
    expect($rio->status)->toBe(ReturnInboundOrder::STATUS_CREATED);

    Queue::fake();
    $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/push', [], $this->adminAuth)->assertOk();
    Queue::assertPushed(PushReturnInboundJob::class, 1);
});

test('TC-RI-004 manual 映射缺编码 → 入库单 exception + 明确原因；详情可见', function () {
    p4WmsConfig(['sku_mapping_mode' => 'manual']);
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 1, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 1);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $rio = ReturnInboundOrder::first();
    expect($rio->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($rio->exception_reason)->toContain('WMS 货品编码');

    $detail = $this->getJson('/api/admin/wms/return-inbound-orders/'.$rio->id, $this->adminAuth)->json('data');
    expect($detail['status'])->toBe('exception')
        ->and($detail['exception_reason'])->toContain('WMS 货品编码');
});

test('TC-RI-005 推送失败 → push_failed + 后台可重推（幂等键重建）', function () {
    config(['wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw']);
    p4WmsConfig(['push_retry_times' => 1]);
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 1, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 1);

    Queue::fake();
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    // 第一次推送：对方返回不可重试的业务失败
    Http::fake(['*' => Http::response(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S03', 'message' => '参数非法'],
    ]), 200)]);
    p4RunPushJobs();

    $rio = ReturnInboundOrder::first();
    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_PUSH_FAILED)
        ->and($rio->last_push_error)->toContain('参数非法')
        ->and($rio->push_times)->toBe(1);

    // 后台重推（队列层重试耗尽同样落到 push_failed，现场保留）
    Queue::fake();
    $res = $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/push', [], $this->adminAuth)->assertOk();
    expect($res->json('data.status'))->toBe(ReturnInboundOrder::STATUS_PENDING_PUSH);
});

test('TC-RI-006 后台列表 + 详情；缺少 wms.return.manage → 403', function () {
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 1, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 1);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();
    $rio = ReturnInboundOrder::first();

    $list = $this->getJson('/api/admin/wms/return-inbound-orders', $this->adminAuth)->assertOk()->json('data');
    expect($list['list'][0]['inbound_no'])->toBe($rio->inbound_no)
        ->and($list['list'][0]['can_manual_received'])->toBeFalse()
        ->and($list['pagination']['total'])->toBe(1);

    // 买家 token 无后台权限
    $this->getJson('/api/admin/wms/return-inbound-orders', $auth)->assertStatus(403);
    $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/cancel', ['reason' => 'x'], $auth)->assertStatus(403);
});

test('TC-RI-007 取消：pending_push 可取消；已取消重复取消 → 409', function () {
    p4WmsConfig(['auto_push_return' => false]);
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 1, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 1);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();
    $rio = ReturnInboundOrder::first();

    $res = $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/cancel', ['reason' => '买家撤销'], $this->adminAuth)->assertOk();
    expect($res->json('data.status'))->toBe(ReturnInboundOrder::STATUS_CANCELLED);

    $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/cancel', ['reason' => '再取消'], $this->adminAuth)
        ->assertStatus(409);

    // 取消后可重建（幂等只看活跃单）
    $rio2 = app(App\Services\Wms\ReturnInboundOrderService::class)->createForRefund($refund->refresh());
    expect($rio2->id)->not->toBe($rio->id);
});

test('TC-RI-008 manual-received（回传丢失兜底）：等价收货处理，库存回加退款完成', function () {
    p4WmsConfig(['auto_push_return' => false]);
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 2);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $svc = app(App\Services\Wms\ReturnInboundOrderService::class);
    $rio = $svc->createForRefund($refund->refresh());
    $svc->markPushed($svc->markPushing($svc->markPendingPush($rio), 'req-m'), 'CN-RET-M');

    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $res = $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/manual-received', [], $this->adminAuth)->assertOk();
    expect($res->json('data.status'))->toBe(ReturnInboundOrder::STATUS_COMPLETED)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);

    // 操作留痕
    expect(SysOperationLog::where('action', 'return_inbound_completed')->count())->toBe(1);
});

// ==================== 回调链路 ====================

test('TC-RI-009 returnorder.confirm 全正品：入库单 completed、库存回加、退款 success、订单 refunded、买家通知', function () {
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 2);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    Queue::fake();
    $rio = ReturnInboundOrder::first();
    $rio->forceFill(['status' => ReturnInboundOrder::STATUS_PUSHED, 'wms_inbound_no' => 'CN-RET-1'])->save();

    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $res = p4Post(p4ReturnConfirmPayload($rio, $sku->sku_code, 2, 'ZP'));
    expect($res['flag'])->toBe('success');
    p4RunCallbackJobs();

    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED)
        ->and($rio->received_at)->not->toBeNull()
        ->and((int) $rio->items()->first()->received_qty)->toBe(2)
        ->and($rio->items()->first()->inventory_type)->toBe('ZP')
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and(Notification::where('type', 'refund_result')->count())->toBeGreaterThan(0);

    // 库存流水对账标识（Step 7）
    expect(\App\Models\InventoryLog::where('biz_type', 'return_inbound')->where('sku_id', $sku->id)->count())->toBe(1);
});

test('TC-RI-010 重复 returnorder.confirm（不同 timestamp）：幂等不重复加库存', function () {
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 2);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    Queue::fake();
    $rio = ReturnInboundOrder::first();
    $rio->forceFill(['status' => ReturnInboundOrder::STATUS_PUSHED])->save();

    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $p1 = p4ReturnConfirmPayload($rio, $sku->sku_code, 2);
    $p1['timestamp'] = '2026-09-20 15:00:01';
    p4Post($p1);
    p4RunCallbackJobs();

    $p2 = p4ReturnConfirmPayload($rio, $sku->sku_code, 2);
    $p2['timestamp'] = '2026-09-20 15:05:00';
    p4Post($p2);
    Queue::assertPushed(ProcessWmsCallbackJob::class, 2);
    p4RunCallbackJobs();

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2)
        ->and($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED);
});

test('TC-RI-011 回传实收 0 → 入库单 exception，退款保持 approved（不放款）', function () {
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 2);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    Queue::fake();
    $rio = ReturnInboundOrder::first();
    $rio->forceFill(['status' => ReturnInboundOrder::STATUS_PUSHED])->save();

    $dbgResp = p4Post(p4ReturnConfirmPayload($rio, $sku->sku_code, 0));
    dump('P4DBG-RESP', $dbgResp);
    p4RunCallbackJobs();
    dump('P4DBG-LOGS', WmsApiLog::latest('id')->first()?->error_msg, WmsApiLog::latest('id')->first()?->api_name, \App\Models\WmsCallbackDedup::count());

    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($rio->exception_reason)->toContain('实收数量为 0')
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_APPROVED);
});

test('TC-RI-012 少件收货（1/2）：库存 +1、差异入 return_exception_reason、退款按原额完成', function () {
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 2);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    Queue::fake();
    $rio = ReturnInboundOrder::first();
    $rio->forceFill(['status' => ReturnInboundOrder::STATUS_PUSHED])->save();

    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    p4Post(p4ReturnConfirmPayload($rio, $sku->sku_code, 1));
    p4RunCallbackJobs();

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 1)
        ->and($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_exception_reason)->toContain('实收 1 件 ≠ 应退 2 件');
});

test('TC-RI-013 超收（3/2）→ exception 不做破坏性操作；残次（CC）不回库存但退款完成', function () {
    p4WmsConfig();
    ['auth' => $auth] = p4Buyer();
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = p4Order($auth, $sku->id, 2, Warehouse::first()->id);
    $refund = p4ApplyReturn($auth, $order, $sku->id, 2);
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    Queue::fake();
    $rio = ReturnInboundOrder::first();
    $rio->forceFill(['status' => ReturnInboundOrder::STATUS_PUSHED])->save();
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    // 超收 → exception
    p4Post(p4ReturnConfirmPayload($rio, $sku->sku_code, 3));
    p4RunCallbackJobs();
    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_APPROVED)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);

    // 残次：转人工后按实情手工标记收货（业务幂等会吞掉同事件重推，人工兜底走 manual-received）
    $res = $this->postJson('/api/admin/wms/return-inbound-orders/'.$rio->id.'/manual-received', [
        'received_details' => [['sku_id' => $sku->id, 'quantity' => 2, 'inventory_type' => 'CC']],
    ], $this->adminAuth)->assertOk();

    expect($res->json('data.status'))->toBe(ReturnInboundOrder::STATUS_COMPLETED)
        ->and($rio->items()->first()->inventory_type)->toBe('CC')
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_received_details[0]['condition'])->toBe(Refund::RETURN_CONDITION_DEFECTIVE);
});

test('TC-RI-014 回传找不到退货入库单 → 告警（审计+站内信）且不占幂等坑', function () {
    p4WmsConfig();
    Queue::fake();

    $payload = [
        'method' => 'taobao.qimen.returnorder.confirm',
        'timestamp' => '2026-09-20 16:00:00',
        'app_key' => 'TEST_KEY',
        'v' => '2.0',
        'sign_method' => 'md5',
        'returnOrder' => ['returnOrderCode' => 'RI-NOT-EXIST', 'orderLines' => ['orderLine' => []]],
    ];
    p4Post($payload);
    p4RunCallbackJobs();

    expect(SysOperationLog::where('module', 'wms')->where('action', 'callback_alert')->count())->toBe(1)
        ->and(Notification::where('type', 'wms_alert')->count())->toBe(2)
        ->and(\App\Models\WmsCallbackDedup::count())->toBe(0);

    $log = WmsApiLog::where('direction', 'inbound')->where('api_name', 'callback_receive')->latest('id')->first();
    expect($log->biz_no)->toBe('RI-NOT-EXIST');
});
