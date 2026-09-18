<?php

/**
 * 支付安全回归测试（SEC-01 沙箱越权 / SEC-02 签名密钥硬编码）
 *
 * 背景：
 * - SEC-01  config/payments.php 中 sandbox 默认 true，且 /payments/sandbox/{paymentNo}
 *          在 auth 组外无鉴权无限流 → 未登录者可把任意支付单置为「支付成功」（0 元购）。
 * - SEC-02  PAY_SIGN_SECRET 默认值为硬编码 'cubeshop-sandbox-secret'（写入库源码）
 *           → 生产未覆盖时任何人都能算出合法 sign，伪造支付成功回调。
 *
 * 本文件同时承担「守卫」职责：一旦有人把默认值加回来，这里的用例必须失败。
 */

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Common\CaptchaService;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => 'secuser'.uniqid(),
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

    $pay = $this->postJson('/api/payments', [
        'order_no' => $this->order['order_no'],
        'channel' => 'mock',
    ], $this->auth)->json('data');

    $this->payNo = $pay['payment_no'] ?? $pay['pay_params']['payment_no'];
    $this->payAmount = (string) Payment::where('payment_no', $this->payNo)->value('amount');
});

afterEach(function () {
    // 部分用例会切到 production 环境，统一还原，避免污染同进程内的后续用例
    app()->detectEnvironment(fn () => 'testing');
});

/*
|--------------------------------------------------------------------------
| SEC-01 沙箱支付
|--------------------------------------------------------------------------
*/

test('SEC-01 生产环境下 sandboxEnabled 恒为 false（即使 PAYMENT_SANDBOX 被误设为 true）', function () {
    // 测试环境本身开启了沙箱，先证明基线为真，避免用例「假通过」
    expect(PaymentService::sandboxEnabled())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    Config::set('payments.sandbox', true); // 模拟最坏情况：生产被误配

    expect(PaymentService::sandboxEnabled())->toBeFalse();
});

test('SEC-01 生产环境调用 sandboxNotify 被服务层硬拦截', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(PaymentService::class)->sandboxNotify($this->payNo))
        ->toThrow(BusinessException::class);

    expect(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_PENDING);
});

test('SEC-01 生产环境未登录请求沙箱路由返回 403 且订单未入账', function () {
    app()->detectEnvironment(fn () => 'production');

    // 不带任何 Token，模拟外部攻击者
    $this->postJson("/api/payments/sandbox/{$this->payNo}")->assertStatus(403);

    expect(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_PENDING)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT);
});

test('SEC-01 回归：testing 环境沙箱仍可用（不破坏本地开发与现有链路）', function () {
    $this->postJson("/api/payments/sandbox/{$this->payNo}")->assertOk();

    expect(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_SUCCESS)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_SHIP);
});

/*
|--------------------------------------------------------------------------
| SEC-02 签名密钥
|--------------------------------------------------------------------------
*/

test('SEC-02 源码中不再存在硬编码默认密钥 cubeshop-sandbox-secret', function () {
    expect(file_get_contents(config_path('payments.php')))
        ->not->toContain('cubeshop-sandbox-secret')
        ->and(file_get_contents(app_path('Services/Payment/Gateways/MockGateway.php')))
        ->not->toContain('cubeshop-sandbox-secret');
});

test('SEC-02 未配置密钥时 MockGateway 返回空串而非硬编码兜底值', function () {
    $origin = Config::get('payments.secret');
    Config::set('payments.secret', null);

    expect(MockGateway::secret())->toBe('');

    Config::set('payments.secret', $origin);
});

test('SEC-02 使用历史默认密钥签名的回调被拒绝且订单不入账', function () {
    // 攻击者沿用源码里泄露过的默认密钥构造签名
    $legacySign = hash_hmac(
        'sha256',
        implode('|', [$this->payNo, 'HACK-TRADE-001', $this->payAmount, Payment::STATUS_SUCCESS]),
        'cubeshop-sandbox-secret',
    );

    $resp = $this->postJson('/api/payments/callback/mock', [
        'payment_no' => $this->payNo,
        'channel_trade_no' => 'HACK-TRADE-001',
        'amount' => $this->payAmount,
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $legacySign,
    ]);

    expect($resp->json('data.ok'))->toBeFalse()
        ->and(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_PENDING)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT);
});

test('SEC-02 空密钥环境下伪造的回调同样被拒绝', function () {
    $origin = Config::get('payments.secret');
    Config::set('payments.secret', null);

    $blindSign = hash_hmac(
        'sha256',
        implode('|', [$this->payNo, 'HACK-TRADE-002', $this->payAmount, Payment::STATUS_SUCCESS]),
        '',
    );

    $resp = $this->postJson('/api/payments/callback/mock', [
        'payment_no' => $this->payNo,
        'channel_trade_no' => 'HACK-TRADE-002',
        'amount' => $this->payAmount,
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $blindSign,
    ]);

    // 空密钥下签名可被任何人复算，验签侧必须 fail-closed（不能因为两侧都是空串就放行）
    expect($resp->json('data.ok'))->toBeFalse()
        ->and(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_PENDING)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT);

    Config::set('payments.secret', $origin);
});

test('SEC-02 正确签名的回调可正常入账（回归保护）', function () {
    $tradeNo = 'SEC-TRADE-'.uniqid();
    $sign = MockGateway::sign($this->payNo, $tradeNo, $this->payAmount, Payment::STATUS_SUCCESS);

    $this->postJson('/api/payments/callback/mock', [
        'payment_no' => $this->payNo,
        'channel_trade_no' => $tradeNo,
        'amount' => $this->payAmount,
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $sign,
    ])->assertOk();

    expect(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_SUCCESS)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_SHIP);
});

test('SEC-02 伪造回调被拒绝时留下审计日志（攻击可被发现）', function () {
    $legacySign = hash_hmac(
        'sha256',
        implode('|', [$this->payNo, 'HACK-AUDIT-1', $this->payAmount, Payment::STATUS_SUCCESS]),
        'cubeshop-sandbox-secret',
    );

    $this->postJson('/api/payments/callback/mock', [
        'payment_no' => $this->payNo,
        'channel_trade_no' => 'HACK-AUDIT-1',
        'amount' => $this->payAmount,
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $legacySign,
    ])->assertOk();

    // 验签失败会在 $result->paymentNo 为空的情况下提前返回，若不留痕则伪造尝试完全不可见
    $log = PaymentLog::where('payment_no', $this->payNo)
        ->where('event', PaymentLog::EVENT_CALLBACK)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->response_data['ok'])->toBeFalse()
        ->and(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_PENDING);
});

test('SEC-02 签名正确但金额被篡改的回调被拒绝（防低金额入账）', function () {
    $tradeNo = 'SEC-TRADE-'.uniqid();
    $hackedAmount = '0.01';
    $sign = MockGateway::sign($this->payNo, $tradeNo, $hackedAmount, Payment::STATUS_SUCCESS);

    $this->postJson('/api/payments/callback/mock', [
        'payment_no' => $this->payNo,
        'channel_trade_no' => $tradeNo,
        'amount' => $hackedAmount,
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $sign,
    ])->assertOk();

    expect(Payment::where('payment_no', $this->payNo)->value('status'))->toBe(Payment::STATUS_PENDING)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT);
});
