<?php

use App\Models\FulfillmentOrder;
use App\Models\ReturnInboundOrder;
use App\Models\SysOperationLog;
use App\Models\User;
use App\Models\WmsApiLog;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WMS 后台运营接口（WMS 计划 P6 / Step 1）
 *
 * 覆盖：调用日志列表/详情（含脱敏红线）、详情接口带最近流水摘要、批量重推
 * （逐条成败 + 审计）、权限隔离。
 */

beforeEach(function () {
    seedRoles();
    config(['wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw']);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/**
 * 无 wms.* 权限的账号（客服角色 cs_agent：只做客服工作台与帮助中心）。
 * 用于验证「日志/健康/批量重推」的权限隔离。
 */
function p6AgentAuth(): array
{
    $username = 'csagent'.bin2hex(random_bytes(3));
    $agent = \App\Models\SysUser::create([
        'username' => $username,
        'password' => \Illuminate\Support\Facades\Hash::make('Cs@123456'),
        'nickname' => '客服',
        'status' => 1,
    ]);
    $agent->syncRoles(['cs_agent']);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();

    return ['Authorization' => 'Bearer '.test()->postJson('/api/auth/login', [
        'username' => $username, 'password' => 'Cs@123456',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
}

function p6Warehouse(): int
{
    return Warehouse::create(['code' => 'WH_P6_'.uniqid(), 'name' => 'P6 仓', 'status' => 1])->id;
}

/** 造一条发货单（只关心状态与单号） */
function p6Outbound(string $status, string $no, int $warehouseId): FulfillmentOrder
{
    $order = p6MakeOrder();

    return FulfillmentOrder::forceCreate([
        'order_id' => $order->id, 'order_no' => $order->order_no, 'outbound_no' => $no,
        'warehouse_id' => $warehouseId, 'provider' => 'cainiao', 'status' => $status,
    ]);
}

function p6MakeOrder(): \App\Models\Order
{
    $user = createTestUser();

    return \App\Models\Order::forceCreate([
        'user_id' => $user->id,
        'order_no' => 'NO'.bin2hex(random_bytes(5)),
        'status' => 'paid',
        'total_amount' => 1000,
        'pay_amount' => 1000,
        'address_snapshot' => ['name' => '张三', 'phone' => '13800138000', 'detail' => '某地'],
    ]);
}

// ============ F5：WMS 调用日志 ============

test('TC-PC-001 日志列表按方向/成功失败筛选', function () {
    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'deliveryorder.create', 'biz_no' => 'FO1', 'success' => true, 'http_status' => 200]);
    WmsApiLog::forceCreate(['direction' => 'inbound', 'provider' => 'cainiao', 'api_name' => 'deliveryorder.confirm', 'biz_no' => 'FO1', 'success' => false, 'http_status' => 500]);

    $all = $this->getJson('/api/admin/wms/logs', $this->adminAuth)->assertOk()->json('data');
    expect($all['list'])->toHaveCount(2);

    $out = $this->getJson('/api/admin/wms/logs?direction=outbound', $this->adminAuth)->assertOk()->json('data');
    expect($out['list'])->toHaveCount(1)
        ->and($out['list'][0]['direction_label'])->toBe('出站');

    $failed = $this->getJson('/api/admin/wms/logs?success=0', $this->adminAuth)->assertOk()->json('data');
    expect($failed['list'])->toHaveCount(1)
        ->and($failed['list'][0]['api_name'])->toBe('deliveryorder.confirm');
});

test('TC-PC-002 日志列表按 request_id / biz_no / 关键词模糊搜索', function () {
    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'queryInventory', 'biz_no' => 'FO-SEARCH', 'request_id' => 'REQ-ABC-123', 'success' => true]);
    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'queryInventory', 'biz_no' => 'FO-OTHER', 'request_id' => 'REQ-XYZ-999', 'success' => true]);

    expect($this->getJson('/api/admin/wms/logs?request_id=ABC', $this->adminAuth)->json('data.list'))->toHaveCount(1)
        ->and($this->getJson('/api/admin/wms/logs?biz_no=SEARCH', $this->adminAuth)->json('data.list'))->toHaveCount(1)
        ->and($this->getJson('/api/admin/wms/logs?keyword=XYZ', $this->adminAuth)->json('data.list'))->toHaveCount(1);
});

test('TC-PC-003 日志详情脱敏：AppSecret 与完整手机号不可见', function () {
    $log = WmsApiLog::forceCreate([
        'direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'deliveryorder.create',
        'biz_no' => 'FO1', 'request_id' => 'REQ-1', 'success' => false,
        'request_body' => ['app_secret' => 'SUPER_SECRET_VALUE', 'buyer_phone' => '13800138000', 'deliveryOrderCode' => 'FO1'],
        'response_body' => ['access_token' => 'TOKEN_XXX', 'mobile' => '13900139000'],
    ]);

    $body = $this->getJson('/api/admin/wms/logs/'.$log->id, $this->adminAuth)->assertOk()->json('data');
    $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);

    expect($encoded)->not->toContain('SUPER_SECRET_VALUE')
        ->and($encoded)->not->toContain('TOKEN_XXX')
        ->and($encoded)->not->toContain('13800138000')
        ->and($encoded)->not->toContain('13900139000')
        // 排障信息必须保留
        ->and($body['request_body']['deliveryOrderCode'])->toBe('FO1')
        ->and($body['request_body']['app_secret'])->toBe('***');
});

test('TC-PC-004 日志详情不存在 → 404', function () {
    $this->getJson('/api/admin/wms/logs/999999', $this->adminAuth)->assertStatus(404);
});

test('TC-PC-005 日志接口需 wms.config.manage，无权限账号被拦截', function () {
    $this->getJson('/api/admin/wms/logs', p6AgentAuth())->assertStatus(403);
    $this->getJson('/api/admin/wms/health', p6AgentAuth())->assertStatus(403);
});

// ============ F2/F4：详情含最近调用流水 ============

test('TC-PC-006 发货单详情返回最近调用流水摘要（不含报文）', function () {
    $wid = p6Warehouse();
    $fo = p6Outbound(FulfillmentOrder::STATUS_PUSH_FAILED, 'FO-LOGS', $wid);

    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'deliveryorder.create', 'biz_no' => 'FO-LOGS', 'request_id' => 'R1', 'success' => false, 'error_msg' => '参数非法']);
    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'deliveryorder.create', 'biz_no' => 'FO-LOGS', 'request_id' => 'R2', 'success' => true]);

    $data = $this->getJson('/api/admin/wms/fulfillment-orders/'.$fo->id, $this->adminAuth)->assertOk()->json('data');

    expect($data['logs'])->toHaveCount(2)
        ->and($data['logs'][0]['request_id'])->toBe('R2')   // 倒序
        ->and($data['logs'][1]['error_msg'])->toBe('参数非法')
        ->and($data)->not->toHaveKey('request_body');
});

test('TC-PC-007 退货入库单详情返回最近调用流水摘要', function () {
    $wid = p6Warehouse();
    $order = p6MakeOrder();
    $refund = \App\Models\Refund::forceCreate([
        'user_id' => $order->user_id, 'order_id' => $order->id, 'order_no' => $order->order_no,
        'refund_no' => 'RF'.bin2hex(random_bytes(4)), 'type' => 'return_refund',
        'amount' => 1000, 'reason' => '七天无理由', 'status' => 'approved',
    ]);
    $rio = ReturnInboundOrder::forceCreate([
        'refund_id' => $refund->id, 'refund_no' => $refund->refund_no,
        'order_id' => $order->id, 'order_no' => $order->order_no,
        'inbound_no' => 'RI-LOGS', 'warehouse_id' => $wid, 'provider' => 'cainiao',
        'status' => ReturnInboundOrder::STATUS_PUSH_FAILED,
    ]);

    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'returnorder.create', 'biz_no' => 'RI-LOGS', 'request_id' => 'RR1', 'success' => false]);

    $data = $this->getJson('/api/admin/wms/return-inbound-orders/'.$rio->id, $this->adminAuth)->assertOk()->json('data');

    expect($data['logs'])->toHaveCount(1)
        ->and($data['logs'][0]['api_name'])->toBe('returnorder.create');
});

// ============ Step 1：批量重推 ============

test('TC-PC-008 批量重推：可推送的入队，不可推送的逐条回报原因', function () {
    $wid = p6Warehouse();
    $ok = p6Outbound(FulfillmentOrder::STATUS_PUSH_FAILED, 'FO-OK', $wid);
    $bad = p6Outbound(FulfillmentOrder::STATUS_SHIPPED, 'FO-BAD', $wid);

    \Illuminate\Support\Facades\Queue::fake();

    $res = $this->postJson('/api/admin/wms/fulfillment-orders/batch-push', [
        'ids' => [$ok->id, $bad->id, 999999],
    ], $this->adminAuth)->assertOk()->json('data');

    expect($res['total'])->toBe(3)
        ->and($res['succeeded'])->toBe(1)
        ->and($res['results'][0]['success'])->toBeTrue()
        ->and($res['results'][1]['success'])->toBeFalse()
        ->and($res['results'][2]['message'])->toBe('发货单不存在');

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Wms\PushOutboundJob::class, 1);
});

test('TC-PC-009 批量重推落审计日志', function () {
    $wid = p6Warehouse();
    $ok = p6Outbound(FulfillmentOrder::STATUS_PUSH_FAILED, 'FO-AUDIT', $wid);
    \Illuminate\Support\Facades\Queue::fake();

    $this->postJson('/api/admin/wms/fulfillment-orders/batch-push', ['ids' => [$ok->id]], $this->adminAuth)->assertOk();

    expect(SysOperationLog::where('action', 'fulfillment_batch_push')->count())->toBe(1);
});

test('TC-PC-010 批量重推需 wms.order.manage，只读账号被拦截', function () {
    $this->postJson('/api/admin/wms/fulfillment-orders/batch-push', ['ids' => [1]], p6AgentAuth())->assertStatus(403);
});

test('TC-PC-011 批量重推参数校验：ids 必填且非空', function () {
    $this->postJson('/api/admin/wms/fulfillment-orders/batch-push', ['ids' => []], $this->adminAuth)
        ->assertStatus(422);

    $this->postJson('/api/admin/wms/fulfillment-orders/batch-push', [], $this->adminAuth)
        ->assertStatus(422);
});
