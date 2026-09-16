<?php

use App\Models\BalanceRecharge;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\UserBalanceLog;
use App\Services\Common\CaptchaService;
use App\Services\Common\ConfigService;
use App\Services\Payment\BalanceRechargeService;
use App\Services\Payment\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 集成测试：前台余额与充值（收银台方案 §6.5 / §9.4，Roadmap P6）
 *
 * 覆盖：充值下单 / 赠送规则 / 风控限额 / 不支持余额支付 / 线下核账入账 / 记录与流水 / 幂等。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $this->username = 'recharge'.uniqid();
    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => $this->username,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$this->token];
    $this->userId = User::where('username', $this->username)->value('id');

    $login = function (string $username, string $password) {
        $c = app(CaptchaService::class)->generate();

        return test()->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $c['captcha_id'],
            'captcha_code' => $c['debug_code'],
        ])->json('data.token');
    };
    $this->operatorAuth = ['Authorization' => 'Bearer '.$login('operator', 'Operator@123')];
});

/* ------------------------------------------------------------------ */
/* 下单与入账                                                          */
/* ------------------------------------------------------------------ */

test('发起充值返回充值 PayParams（复用订单支付链路）', function () {
    $res = $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00',
        'channel' => 'mock',
    ], $this->auth)->json('data');

    expect($res['biz_type'])->toBe(Payment::BIZ_TYPE_RECHARGE)
        ->and($res['channel'])->toBe('mock')
        ->and($res['pay_params']['type'])->toBe('mock')
        ->and(BalanceRecharge::where('recharge_no', $res['recharge_no'])->exists())->toBeTrue()
        ->and(Payment::where('payment_no', $res['payment_no'])->whereNull('order_id')->exists())->toBeTrue();
});

test('充值支付成功后按本金+赠送入账并写入余额流水', function () {
    app(ConfigService::class)->set('payment.recharge_gift_rules', json_encode([['amount' => 100, 'gift' => 10]]));

    $res = $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00',
        'channel' => 'mock',
    ], $this->auth)->json('data');

    expect($res['gift_amount'])->toBe('10.00');

    // 沙箱模拟渠道回调成功
    $this->postJson("/api/payments/sandbox/{$res['payment_no']}");

    $recharge = BalanceRecharge::where('recharge_no', $res['recharge_no'])->first();
    expect($recharge->status)->toBe(BalanceRecharge::STATUS_SUCCESS)
        ->and($recharge->paid_at)->not->toBeNull()
        ->and(app(BalanceService::class)->balance($this->userId))->toBe('110.00')
        ->and((string) UserBalance::where('user_id', $this->userId)->value('total_recharge'))->toBe('100.00')
        ->and(UserBalanceLog::where('user_id', $this->userId)
            ->where('type', UserBalanceLog::TYPE_RECHARGE)
            ->where('related_type', 'recharge')
            ->where('related_id', $recharge->id)
            ->count())->toBe(1);
});

test('重复回调幂等：余额只入账一次', function () {
    app(ConfigService::class)->set('payment.recharge_gift_rules', json_encode([['amount' => 100, 'gift' => 10]]));

    $res = $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00', 'channel' => 'mock',
    ], $this->auth)->json('data');

    $this->postJson("/api/payments/sandbox/{$res['payment_no']}");
    $this->postJson("/api/payments/sandbox/{$res['payment_no']}");

    expect(app(BalanceService::class)->balance($this->userId))->toBe('110.00')
        ->and(UserBalanceLog::where('user_id', $this->userId)->where('type', UserBalanceLog::TYPE_RECHARGE)->count())->toBe(1);
});

/* ------------------------------------------------------------------ */
/* 赠送规则                                                            */
/* ------------------------------------------------------------------ */

test('赠送规则命中最高档、未达标不送', function () {
    app(ConfigService::class)->set('payment.recharge_gift_rules', json_encode([
        ['amount' => 100, 'gift' => 10],
        ['amount' => 200, 'gift' => 30],
    ]));

    $service = app(BalanceRechargeService::class);
    expect($service->giftFor('250.00'))->toBe('30.00')
        ->and($service->giftFor('200.00'))->toBe('30.00')
        ->and($service->giftFor('100.00'))->toBe('10.00')
        ->and($service->giftFor('50.00'))->toBe('0.00');
});

/* ------------------------------------------------------------------ */
/* 风控限额                                                            */
/* ------------------------------------------------------------------ */

test('低于最小金额被拒（40000）', function () {
    $this->postJson('/api/user/balance/recharges', [
        'amount' => '5.00', 'channel' => 'mock',
    ], $this->auth)->assertJsonPath('code', 40000);
});

test('高于单笔上限被拒（40000）', function () {
    $this->postJson('/api/user/balance/recharges', [
        'amount' => '6000.00', 'channel' => 'mock',
    ], $this->auth)->assertJsonPath('code', 40000);
});

test('单日累计超限被拒（40000）', function () {
    app(ConfigService::class)->set('payment.recharge_max_daily', '150.00');

    $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00', 'channel' => 'mock',
    ], $this->auth)->assertJsonPath('code', 0);

    // 100 + 100 = 200 > 150
    $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00', 'channel' => 'mock',
    ], $this->auth)->assertJsonPath('code', 40000);
});

test('充值不支持余额支付（422）', function () {
    $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00', 'channel' => 'balance',
    ], $this->auth)->assertStatus(422);
});

test('充值总开关关闭时拒绝（40000）', function () {
    app(ConfigService::class)->set('payment.recharge_enabled', '0');

    $this->postJson('/api/user/balance/recharges', [
        'amount' => '100.00', 'channel' => 'mock',
    ], $this->auth)->assertJsonPath('code', 40000);
});

/* ------------------------------------------------------------------ */
/* 线下充值                                                            */
/* ------------------------------------------------------------------ */

test('线下充值进入待核账并回执收款账户', function () {
    $res = $this->postJson('/api/user/balance/recharges', [
        'amount' => '200.00',
        'channel' => 'offline',
        'extra' => [
            'payer_name' => '张三',
            'payer_account' => '6222 **** 1234',
            'transfer_no' => 'T20260916009',
            'voucher_url' => '/storage/vouchers/1/x.png',
        ],
    ], $this->auth)->json('data');

    expect($res['status'])->toBe(Payment::STATUS_REVIEWING)
        ->and($res['pay_params']['type'])->toBe('voucher')
        ->and(BalanceRecharge::where('recharge_no', $res['recharge_no'])->value('status'))->toBe(BalanceRecharge::STATUS_REVIEWING)
        // 未核账不入账
        ->and(app(BalanceService::class)->balance($this->userId))->toBe('0.00');
});

test('线下充值核账通过后入账', function () {
    $res = $this->postJson('/api/user/balance/recharges', [
        'amount' => '200.00',
        'channel' => 'offline',
        'extra' => [
            'payer_name' => '张三',
            'transfer_no' => 'T20260916010',
            'voucher_url' => '/storage/vouchers/1/y.png',
        ],
    ], $this->auth)->json('data');

    $recharge = BalanceRecharge::where('recharge_no', $res['recharge_no'])->first();

    $this->postJson("/api/admin/balance-recharges/{$recharge->id}/review", ['pass' => true], $this->operatorAuth)
        ->assertOk();

    expect($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_SUCCESS)
        ->and(app(BalanceService::class)->balance($this->userId))->toBe('200.00');
});

/* ------------------------------------------------------------------ */
/* 余额 / 记录 / 流水                                                   */
/* ------------------------------------------------------------------ */

test('余额汇总与空态默认值', function () {
    $res = $this->getJson('/api/user/balance', $this->auth)->json('data');

    expect($res['balance'])->toBe('0.00')
        ->and($res['total_recharge'])->toBe('0.00')
        ->and($res['total_consume'])->toBe('0.00');
});

test('充值记录接口分页返回', function () {
    $this->postJson('/api/user/balance/recharges', ['amount' => '100.00', 'channel' => 'mock'], $this->auth);
    $this->postJson('/api/user/balance/recharges', ['amount' => '200.00', 'channel' => 'mock'], $this->auth);

    $res = $this->getJson('/api/user/balance/recharges', $this->auth)->json('data');

    expect($res['pagination']['total'])->toBe(2)
        ->and($res['list'][0])->toHaveKeys(['recharge_no', 'amount', 'gift_amount', 'status_label']);
});

test('余额流水接口返回流水类型标签', function () {
    app(BalanceService::class)->credit($this->userId, '66.00', UserBalanceLog::TYPE_RECHARGE, 'recharge', 1, '测试入账');

    $res = $this->getJson('/api/user/balance/logs', $this->auth)->json('data');

    expect($res['pagination']['total'])->toBe(1)
        ->and($res['list'][0]['type_label'])->toBe('充值')
        ->and($res['list'][0]['balance_after'])->toBe('66.00');
});

test('未登录访问余额接口返回 401', function () {
    $this->getJson('/api/user/balance')->assertStatus(401);
    $this->getJson('/api/user/balance/recharges')->assertStatus(401);
    $this->postJson('/api/user/balance/recharges', ['amount' => '100.00', 'channel' => 'mock'])->assertStatus(401);
});
