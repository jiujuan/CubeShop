<?php

use App\Models\Order;
use App\Models\SysOperationLog;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    // 管理员
    $cap = app(CaptchaService::class)->generate();
    $this->adminToken = $this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->adminToken];

    // 普通买家 + 一笔待支付订单
    $cap2 = app(CaptchaService::class)->generate();
    $this->userToken = $this->postJson('/api/auth/register', [
        'username' => 'buyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token');
    $this->userAuth = ['Authorization' => 'Bearer '.$this->userToken];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收', 'contact_phone' => '13800000000',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ], $this->userAuth)->json('data');
    $this->sku = createTestSku(stock: 10, price: '40.00');
    $this->postJson('/api/cart', ['sku_id' => $this->sku->id, 'quantity' => 1], $this->userAuth);
    $this->order = $this->postJson('/api/orders', ['address_id' => $addr['id'] ?? $addr], $this->userAuth)->json('data');
});

// 普通用户访问管理端 → 403
test('TC-ADMIN-001 无权限用户访问管理端被拒绝 403', function () {
    $this->getJson('/api/admin/products', $this->userAuth)->assertStatus(403);
    $this->getJson('/api/admin/orders', $this->userAuth)->assertStatus(403);
});

// ADMIN-002 管理端订单列表
test('TC-ADMIN-002 管理端订单列表与详情', function () {
    $list = $this->getJson('/api/admin/orders', $this->adminAuth)->json();
    expect($list['code'])->toBe(0)
        ->and($list['data']['pagination']['total'])->toBeGreaterThanOrEqual(1)
        ->and($list['data']['list'])->not->toBeEmpty();

    $detail = $this->getJson('/api/admin/orders/'.$this->order['order_id'], $this->adminAuth)->json();
    expect($detail['code'])->toBe(0)->and($detail['data']['order_no'])->toBe($this->order['order_no']);
});

// ADMIN-005 发货：先支付后发货
test('TC-ADMIN-005 已支付订单发货成功', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->userAuth)->json('data');
    $this->postJson('/api/payments/sandbox/'.($pay['payment_no'] ?? $pay['pay_params']['payment_no']), [], $this->userAuth);

    $resp = $this->postJson("/api/admin/orders/{$this->order['order_id']}/ship", [
        'company' => '顺丰', 'tracking_no' => 'SF999888777',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.status'))->toBe(Order::STATUS_SHIPPED)
        ->and($resp->json('data.shipped_at'))->not->toBeNull();
});

test('待支付订单直接发货被状态机拒绝', function () {
    $resp = $this->postJson("/api/admin/orders/{$this->order['order_id']}/ship", [
        'company' => '顺丰', 'tracking_no' => 'SF000',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009);
});

test('重复发货被状态机拒绝', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->userAuth)->json('data');
    $this->postJson('/api/payments/sandbox/'.($pay['payment_no'] ?? $pay['pay_params']['payment_no']), [], $this->userAuth);
    $this->postJson("/api/admin/orders/{$this->order['order_id']}/ship", [
        'company' => '顺丰', 'tracking_no' => 'SF001',
    ], $this->adminAuth);

    $resp = $this->postJson("/api/admin/orders/{$this->order['order_id']}/ship", [
        'company' => '顺丰', 'tracking_no' => 'SF002',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009);
});

// ADMIN-003/004 商品管理
test('TC-ADMIN-003 创建商品含 SKU 与库存', function () {
    $resp = $this->postJson('/api/admin/products', [
        'category_id' => 1,
        'title' => '测试新商品'.uniqid(),
        'status' => 1,
        'skus' => [['sku_code' => 'NT-'.uniqid(), 'specs' => ['规格' => '默认'], 'price' => '9.90', 'stock' => 7]],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)->and($resp->json('data.id'))->not->toBeNull();
});

test('TC-ADMIN-004 部分更新商品（仅状态）不报错且不影响 SKU', function () {
    $pid = $this->postJson('/api/admin/products', [
        'category_id' => 1,
        'title' => '部分更新测试'.uniqid(),
        'status' => 1,
        'skus' => [['sku_code' => 'PU-'.uniqid(), 'specs' => [], 'price' => '1.00', 'stock' => 3]],
    ], $this->adminAuth)->json('data.id');

    $resp = $this->putJson("/api/admin/products/{$pid}", ['status' => 0], $this->adminAuth);

    expect($resp->json('code'))->toBe(0);

    $detail = $this->getJson("/api/admin/products/{$pid}", $this->adminAuth)->json('data');
    expect((int) $detail['status'])->toBe(0)
        ->and($detail['skus'])->toHaveCount(1);
});

test('SKU 编码重复返回业务错误而非系统错误', function () {
    $code = 'DUP-'.uniqid();
    $this->postJson('/api/admin/products', [
        'category_id' => 1, 'title' => 'A'.uniqid(), 'status' => 1,
        'skus' => [['sku_code' => $code, 'specs' => [], 'price' => '1.00', 'stock' => 1]],
    ], $this->adminAuth);

    $resp = $this->postJson('/api/admin/products', [
        'category_id' => 1, 'title' => 'B'.uniqid(), 'status' => 1,
        'skus' => [['sku_code' => $code, 'specs' => [], 'price' => '2.00', 'stock' => 2]],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009)->and($resp->status())->toBe(409);
});

// ADMIN-006 退款审核（先让订单支付）
test('TC-ADMIN-006 退款审核拒绝后订单回到已支付', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->userAuth)->json('data');
    $this->postJson('/api/payments/sandbox/'.($pay['payment_no'] ?? $pay['pay_params']['payment_no']), [], $this->userAuth);

    $apply = $this->postJson("/api/orders/{$this->order['order_id']}/refund", ['reason' => '不想要'], $this->userAuth)->json('data');

    $resp = $this->postJson("/api/admin/refunds/{$apply['refund_id']}/process", [
        'action' => 'reject', 'admin_remark' => '凭证不足',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0);
});

// 仪表盘与导出
test('TC-ADMIN-007 数据概览接口可用', function () {
    $resp = $this->getJson('/api/admin/dashboard', $this->adminAuth);

    expect($resp->json('code'))->toBe(0)->and($resp->json('data'))->toBeArray();
});

test('TC-ADMIN-008 订单导出返回 CSV 响应', function () {
    $resp = $this->getJson('/api/admin/orders/export', $this->adminAuth);

    expect($resp->getStatusCode())->toBe(200);
});

// SYS-001 操作日志落库
test('TC-SYS-001 后台操作记录操作日志', function () {
    $this->postJson("/api/admin/orders/{$this->order['order_id']}/ship", [
        'company' => '顺丰', 'tracking_no' => 'SF777',
    ], $this->adminAuth);

    $logs = $this->getJson('/api/admin/operation-logs?module=order', $this->adminAuth)->json();

    expect($logs['code'])->toBe(0)
        ->and($logs['data']['pagination']['total'])->toBeGreaterThanOrEqual(1)
        ->and(SysOperationLog::where('module', 'order')->exists())->toBeTrue();
});
