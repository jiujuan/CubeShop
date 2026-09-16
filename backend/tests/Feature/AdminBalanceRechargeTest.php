<?php

use App\Models\BalanceRecharge;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Common\CaptchaService;
use App\Services\Payment\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 集成测试：后台余额充值单管理 + 线下转账核账（§5.2 / §6.2 / §6.5）
 * 权限：balance.recharge.view（运营+超管）；核账 payment.offline.review（运营+超管）。
 */
beforeEach(function () {
    test()->seed(\Database\Seeders\RolePermissionSeeder::class);

    $login = function (string $username, string $password) {
        $cap = app(CaptchaService::class)->generate();

        return test()->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token');
    };

    $this->adminAuth = ['Authorization' => 'Bearer '.$login('admin', 'Admin@123')];
    $this->operatorAuth = ['Authorization' => 'Bearer '.$login('operator', 'Operator@123')];
    $this->buyer = createTestUser('recharge-buyer');
});

/** 创建一笔 reviewing 的线下充值单 + 关联支付单 */
function makeRecharge($test, string $amount = '100.00', string $gift = '10.00'): BalanceRecharge
{
    $recharge = BalanceRecharge::create([
        'recharge_no' => 'R'.now()->format('Ymd').str_pad((string) random_int(1, 9999), 4, '0'),
        'user_id' => $test->buyer->id,
        'amount' => $amount,
        'gift_amount' => $gift,
        'channel' => Payment::CHANNEL_OFFLINE,
        'status' => BalanceRecharge::STATUS_REVIEWING,
        'payer_name' => '张三',
        'transfer_no' => 'TR001',
        'voucher_url' => '/v/1.png',
    ]);

    $payment = Payment::create([
        'payment_no' => 'PAY-'.uniqid(),
        'order_id' => null,
        'order_no' => null,
        'user_id' => $test->buyer->id,
        'channel' => Payment::CHANNEL_OFFLINE,
        'amount' => $amount,
        'status' => Payment::STATUS_REVIEWING,
        'biz_type' => Payment::BIZ_TYPE_RECHARGE,
        'biz_no' => $recharge->recharge_no,
    ]);
    $recharge->forceFill(['payment_id' => $payment->id])->save();

    return $recharge;
}

test('运营可查看充值单列表', function () {
    makeRecharge($this);
    $this->getJson('/api/admin/balance-recharges', $this->operatorAuth)
        ->assertOk()
        ->assertJsonPath('data.list.0.status_label', '待核账');
});

test('核账通过：充值单成功并幂等入账（本金+赠送）', function () {
    app(BalanceService::class)->account($this->buyer->id); // 预建账户，初始 0
    $recharge = makeRecharge($this);

    $this->postJson("/api/admin/balance-recharges/{$recharge->id}/review", ['pass' => true], $this->operatorAuth)
        ->assertOk()
        ->assertJsonPath('data.status', BalanceRecharge::STATUS_SUCCESS);

    $recharge->refresh();
    expect($recharge->status)->toBe(BalanceRecharge::STATUS_SUCCESS);

    $balance = app(BalanceService::class)->balance($this->buyer->id);
    expect($balance)->toBe('110.00'); // 100 + 10 赠送

    // 幂等：再次核账应被拒（状态已非 reviewing）
    $this->postJson("/api/admin/balance-recharges/{$recharge->id}/review", ['pass' => true], $this->operatorAuth)
        ->assertStatus(409);

    // 余额流水唯一
    expect(\App\Models\UserBalanceLog::query()
        ->where('related_type', 'recharge')->where('related_id', $recharge->id)->count())->toBe(1);
});

test('核账驳回：充值单失败且必须填原因', function () {
    $recharge = makeRecharge($this);

    $this->postJson("/api/admin/balance-recharges/{$recharge->id}/review", ['pass' => false], $this->operatorAuth)
        ->assertStatus(400);

    $this->postJson("/api/admin/balance-recharges/{$recharge->id}/review", ['pass' => false, 'remark' => '凭证不清'], $this->operatorAuth)
        ->assertOk()
        ->assertJsonPath('data.status', BalanceRecharge::STATUS_FAILED);

    expect($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_FAILED);
    // 驳回不入账
    expect(app(BalanceService::class)->balance($this->buyer->id))->toBe('0.00');
});

test('线下订单支付核账通过：订单转已支付', function () {
    $sku = createTestSku(stock: 5, price: '80.00');
    $order = Order::create([
        'user_id' => $this->buyer->id,
        'order_no' => 'SO'.uniqid(),
        'status' => Order::STATUS_PENDING_PAYMENT,
        'pay_amount' => '80.00',
        'total_amount' => '80.00',
        'address_id' => 1,
        'address_snapshot' => ['contact_name' => '张三', 'contact_phone' => '13800001111', 'detail' => '科技园'],
    ]);
    $payment = Payment::create([
        'payment_no' => 'PAY-'.uniqid(),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $this->buyer->id,
        'channel' => Payment::CHANNEL_OFFLINE,
        'amount' => '80.00',
        'status' => Payment::STATUS_REVIEWING,
        'biz_type' => Payment::BIZ_TYPE_ORDER,
        'biz_no' => $order->order_no,
    ]);

    $this->postJson("/api/admin/payments/{$payment->id}/review", ['pass' => true], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('data.status', Payment::STATUS_SUCCESS);

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});
