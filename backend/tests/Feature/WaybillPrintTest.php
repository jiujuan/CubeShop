<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 建一笔待发货订单（镜像 WaybillIssuanceTest::waybillOrder） */
function printWaybillOrder(string $status = Order::STATUS_PENDING_SHIP): Order
{
    $user = createTestUser('wp'.uniqid());

    return Order::create([
        'order_no' => 'WP'.uniqid(),
        'user_id' => $user->id,
        'status' => $status,
        'total_amount' => '10.00',
        'freight_amount' => '0.00',
        'pay_amount' => '10.00',
        'address_snapshot' => [
            'contact_name' => '张三',
            'contact_phone' => '13800000000',
            'province' => '广东', 'city' => '深圳', 'district' => '南山', 'detail_address' => '科技园1号',
            'full_address' => '广东省深圳市南山区科技园1号',
        ],
    ]);
}

// ---------- 端点：HTTP ----------

test('waybill print endpoint returns printable html for mock-shipped order', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = printWaybillOrder();
    app(OrderService::class)->shipForShipment($order, 'SF', '顺丰速运', 'UNUSED', issueWaybill: true);

    $shipping = Shipping::where('order_id', $order->id)->first();

    $res = $this->get('/api/admin/shippings/'.$shipping->id.'/waybill', $this->adminAuth);

    $res->assertOk();
    $res->assertHeader('Content-Type', 'text/html; charset=utf-8');
    // 打印页含运单号、承运、出单模板与打印按钮
    $res->assertSee($shipping->tracking_no);
    $res->assertSee('顺丰速运');
    $res->assertSee('mock-waybill');
    $res->assertSee('打印面单');
});

test('waybill print endpoint returns 40022 when no printable template', function () {
    $order = printWaybillOrder();
    $shipping = Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'MANUAL123',
        'trace_status' => Shipping::TRACE_PENDING,
        'waybill_channel' => null,
        'waybill_data' => null,
    ]);

    $res = $this->getJson('/api/admin/shippings/'.$shipping->id.'/waybill', $this->adminAuth);

    $res->assertOk();
    $res->assertJson(['code' => 40022]);
});

test('waybill print endpoint returns 40004 when shipping missing', function () {
    $res = $this->getJson('/api/admin/shippings/999999/waybill', $this->adminAuth);

    $res->assertOk();
    $res->assertJson(['code' => 40004]);
});

test('waybill print endpoint supports format=json', function () {
    $order = printWaybillOrder();
    $shipping = Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'KF123456',
        'trace_status' => Shipping::TRACE_PENDING,
        'waybill_channel' => 'kuaidi100',
        'waybill_data' => ['print_template' => '<div class="kuaidi">label</div>'],
    ]);

    $res = $this->getJson('/api/admin/shippings/'.$shipping->id.'/waybill?format=json', $this->adminAuth);

    $res->assertOk();
    $res->assertJson(['code' => 0]);
    $res->assertJsonPath('data.tracking_no', 'KF123456');
    $res->assertJsonPath('data.channel', 'kuaidi100');
    $res->assertJsonPath('data.template', '<div class="kuaidi">label</div>');
});

// ---------- 解析单元：Shipping::resolvePrintTemplate ----------

test('resolvePrintTemplate prefers top-level print_template', function () {
    $s = new Shipping(['waybill_data' => ['print_template' => '<div>top</div>']]);
    expect($s->resolvePrintTemplate())->toBe('<div>top</div>');
});

test('resolvePrintTemplate falls back to kuaidi100 data.printTemplate', function () {
    $s = new Shipping(['waybill_data' => ['data' => ['printTemplate' => '<p>inner</p>']]]);
    expect($s->resolvePrintTemplate())->toBe('<p>inner</p>');
});

test('resolvePrintTemplate decodes base64 html', function () {
    $b64 = base64_encode('<p>decoded html</p>');
    $s = new Shipping(['waybill_data' => ['data' => ['printTemplateBase64' => $b64]]]);
    expect($s->resolvePrintTemplate())->toBe('<p>decoded html</p>');
});

test('resolvePrintTemplate wraps base64 image as data uri', function () {
    $png = "\x89PNG\r\n\x1a\n".str_repeat('x', 24);
    $b64 = base64_encode($png);
    $s = new Shipping(['waybill_data' => ['data' => ['printTemplateBase64' => $b64]]]);
    $out = $s->resolvePrintTemplate();
    expect($out)->toStartWith('<img src="data:image/png;base64,');
});

test('resolvePrintTemplate escapes plain text as pre', function () {
    $s = new Shipping(['waybill_data' => ['data' => ['printTemplate' => 'plain text label']]]);
    $out = $s->resolvePrintTemplate();
    expect($out)->toStartWith('<pre');
    expect($out)->toContain('plain text label');
});

test('resolvePrintTemplate returns null when no data', function () {
    $s = new Shipping(['waybill_data' => null]);
    expect($s->resolvePrintTemplate())->toBeNull();

    $s2 = new Shipping(['waybill_data' => ['channel' => 'mock']]);
    expect($s2->resolvePrintTemplate())->toBeNull();
});
