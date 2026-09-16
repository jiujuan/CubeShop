<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => 'payuser'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$this->token];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '路 1 号',
    ], $this->auth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '30.00');
    $this->postJson('/api/cart', ['sku_id' => $this->sku->id, 'quantity' => 2], $this->auth);
    $this->order = $this->postJson('/api/orders', ['address_id' => $this->addressId], $this->auth)->json('data');
});

// ORDER-004 发起支付
test('TC-ORDER-004 发起支付返回沙箱参数', function () {
    $resp = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->auth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.pay_params.mode'))->toBe('sandbox')
        ->and($resp->json('data.pay_params.sandbox_pay_url'))->toContain('/api/payments/sandbox/');
});

// ORDER-005 沙箱支付成功：订单转待发货 + 库存扣减
test('TC-ORDER-005 沙箱支付成功后订单进入待发货且库存确认扣减', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'alipay'], $this->auth)->json('data');
    $payNo = $pay['payment_no'] ?? $pay['pay_params']['payment_no'];

    $resp = $this->postJson("/api/payments/sandbox/{$payNo}", [], $this->auth);

    expect($resp->json('code'))->toBe(0)
        ->and($this->getJson('/api/orders/'.$this->order['order_id'], $this->auth)->json('data.status'))->toBe(Order::STATUS_PENDING_SHIP)
        ->and((int) Inventory::where('sku_id', $this->sku->id)->value('locked_stock'))->toBe(0)
        ->and((int) Inventory::where('sku_id', $this->sku->id)->value('stock'))->toBe(8);

    // 支付单状态
    $query = $this->getJson("/api/payments/{$payNo}", $this->auth)->json();
    expect($query['data']['status'])->toBe(Payment::STATUS_SUCCESS);
});

// 重复沙箱支付回调：幂等
test('重复支付回调幂等且只扣一次库存', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->auth)->json('data');
    $payNo = $pay['payment_no'] ?? $pay['pay_params']['payment_no'];

    $this->postJson("/api/payments/sandbox/{$payNo}", [], $this->auth);
    $this->postJson("/api/payments/sandbox/{$payNo}", [], $this->auth);

    expect((int) Inventory::where('sku_id', $this->sku->id)->value('stock'))->toBe(8)
        ->and((int) Inventory::where('sku_id', $this->sku->id)->value('locked_stock'))->toBe(0);
});

// 支付后取消被状态机拒绝（订单详情可见 paid 状态）
test('已支付订单不能再次发起支付', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->auth)->json('data');
    $payNo = $pay['payment_no'] ?? $pay['pay_params']['payment_no'];
    $this->postJson("/api/payments/sandbox/{$payNo}", [], $this->auth);

    $resp = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->auth);

    expect($resp->json('code'))->toBe(40009);
});

// 他人支付单不可见
test('他人支付单查询返回 404', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->auth)->json('data');
    $payNo = $pay['payment_no'] ?? $pay['pay_params']['payment_no'];

    $cap = app(CaptchaService::class)->generate();
    $otherToken = $this->postJson('/api/auth/register', [
        'username' => 'payhacker'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');

    $this->getJson("/api/payments/{$payNo}", ['Authorization' => 'Bearer '.$otherToken])->assertStatus(404);
});

// 取消订单后关闭待支付单
test('取消订单后原支付单关闭', function () {
    $pay = $this->postJson('/api/payments', ['order_no' => $this->order['order_no'], 'channel' => 'wechat'], $this->auth)->json('data');
    $payNo = $pay['payment_no'] ?? $pay['pay_params']['payment_no'];

    $this->postJson("/api/orders/{$this->order['order_id']}/cancel", [], $this->auth);

    expect(Payment::where('payment_no', $payNo)->value('status'))->toBe(Payment::STATUS_CLOSED);
});
