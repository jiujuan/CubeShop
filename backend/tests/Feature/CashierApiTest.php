<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\SysUser;
use App\Models\UserBalance;
use App\Models\UserBalanceLog;
use App\Services\Common\CaptchaService;
use App\Services\Payment\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => 'cashier'.uniqid(),
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
    $this->userId = Order::where('order_no', $this->order['order_no'])->value('user_id');
});

/** 收银台首屏渠道列表：返回已启用渠道，含余额与收款账户 */
test('GET /payments/channels 返回已启用渠道与余额/收款账户', function () {
    $res = $this->getJson('/api/payments/channels', $this->auth)->json('data');

    $codes = collect($res['channels'])->pluck('code')->all();
    expect($res)->toHaveKey('default_channel')
        ->and($codes)->toContain('wechat')
        ->and($codes)->toContain('alipay')
        ->and($codes)->toContain('balance')
        ->and($codes)->toContain('offline');

    $balance = collect($res['channels'])->firstWhere('code', 'balance');
    expect($balance)->toHaveKey('balance');

    $offline = collect($res['channels'])->firstWhere('code', 'offline');
    expect($offline)->toHaveKey('receipt');
});

/** recharge 场景过滤余额支付，并附带充值配置 */
test('recharge 场景渠道列表不含余额支付且含充值配置', function () {
    $res = $this->getJson('/api/payments/channels?scene=recharge', $this->auth)->json('data');

    $codes = collect($res['channels'])->pluck('code')->all();
    expect($codes)->not->toContain('balance')
        ->and($res)->toHaveKey('recharge')
        ->and($res['recharge'])->toHaveKey('amounts')
        ->and($res['recharge'])->toHaveKey('gift_rules');
});

test('未登录访问渠道列表返回 401', function () {
    $this->getJson('/api/payments/channels')->assertStatus(401);
});

/** 余额不足：拒绝并订单保持待支付 */
test('余额不足时余额支付被拒（40000）', function () {
    $resp = $this->postJson('/api/payments', [
        'order_no' => $this->order['order_no'],
        'channel' => 'balance',
    ], $this->auth);

    expect($resp->json('code'))->toBe(40000)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT);
});

/** 余额充足：同步扣款、入账流水、订单转 paid */
test('余额充足时余额支付成功并扣减余额', function () {
    app(BalanceService::class)->credit($this->userId, '500.00');

    $resp = $this->postJson('/api/payments', [
        'order_no' => $this->order['order_no'],
        'channel' => 'balance',
    ], $this->auth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.status'))->toBe(Payment::STATUS_SUCCESS)
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PAID)
        ->and((string) UserBalance::where('user_id', $this->userId)->value('balance'))->toBe(bcsub('500.00', (string) $this->order['pay_amount'], 2))
        ->and(UserBalanceLog::where('user_id', $this->userId)->where('type', UserBalanceLog::TYPE_CONSUME)->exists())->toBeTrue();
});

/** 线下转账：提交后进入待核账，订单保持待支付 */
test('线下转账提交后进入待核账且订单待支付', function () {
    $resp = $this->postJson('/api/payments', [
        'order_no' => $this->order['order_no'],
        'channel' => 'offline',
        'extra' => [
            'payer_name' => '张三',
            'payer_account' => '6222 **** 1234',
            'transfer_no' => 'T20260916001',
            'transferred_at' => '2026-09-16 10:30:00',
            'voucher_url' => '/storage/vouchers/1/x.png',
        ],
    ], $this->auth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.status'))->toBe(Payment::STATUS_REVIEWING)
        ->and($resp->json('data.pay_params.type'))->toBe('voucher')
        ->and(Order::where('order_no', $this->order['order_no'])->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT);
});

test('线下转账缺少凭证被拒（40000）', function () {
    $this->postJson('/api/payments', [
        'order_no' => $this->order['order_no'],
        'channel' => 'offline',
        'extra' => ['payer_name' => '张三'],
    ], $this->auth)->assertJsonPath('code', 40000);
});

/** 模拟支付：返回沙箱地址，sync 触发查单，沙箱回调后成功 */
test('模拟支付 + 主动查单补偿 + 沙箱回调成功', function () {
    $pay = $this->postJson('/api/payments', [
        'order_no' => $this->order['order_no'],
        'channel' => 'mock',
    ], $this->auth)->json('data');

    expect($pay['pay_params']['type'])->toBe('mock')
        ->and($pay['pay_params']['sandbox_pay_url'])->toContain('/api/payments/sandbox/');

    // 主动查单（尚未支付，仍 pending）
    $sync = $this->postJson("/api/payments/{$pay['payment_no']}/sync", [], $this->auth)->json('data');
    expect($sync['synced'])->toBeTrue()
        ->and($sync['status'])->toBe(Payment::STATUS_PENDING);

    // 沙箱模拟渠道回调成功
    $this->postJson("/api/payments/sandbox/{$pay['payment_no']}", [], $this->auth);
    expect(Payment::where('payment_no', $pay['payment_no'])->value('status'))->toBe(Payment::STATUS_SUCCESS);

    // 再次查单应已成功
    $sync2 = $this->postJson("/api/payments/{$pay['payment_no']}/sync", [], $this->auth)->json('data');
    expect($sync2['status'])->toBe(Payment::STATUS_SUCCESS);
});

/** 凭证上传：鉴权、成功、超限、未登录 */
test('凭证上传接口：成功 / 超限 / 未登录', function () {
    Storage::fake('public');

    $ok = $this->postJson('/api/user/upload-voucher', [
        'file' => UploadedFile::fake()->create('v.jpg', 50, 'image/jpeg'),
    ], $this->auth);
    expect($ok->json('code'))->toBe(0)->and($ok->json('data.url'))->toContain('/storage/vouchers/');

    $big = $this->postJson('/api/user/upload-voucher', [
        'file' => UploadedFile::fake()->create('big.jpg', 4000, 'image/jpeg'),
    ], $this->auth);
    $big->assertStatus(422);

    $this->postJson('/api/user/upload-voucher', [
        'file' => UploadedFile::fake()->create('v.jpg', 10, 'image/jpeg'),
    ])->assertStatus(401);
});
