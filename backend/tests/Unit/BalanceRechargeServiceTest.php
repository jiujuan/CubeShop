<?php

use App\Exceptions\BusinessException;
use App\Models\BalanceRecharge;
use App\Models\Payment;
use App\Services\Common\ConfigService;
use App\Services\Payment\BalanceRechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 单元测试：余额充值服务（收银台方案 §6.5，Roadmap P6）
 *
 * 覆盖赠送规则、金额区间、单日累计限额、渠道限制与开关。
 */
beforeEach(function () {
    $this->user = createTestUser('recharge-unit');
    $this->config = app(ConfigService::class);
    $this->config->flush(); // 清缓存，避免上一用例的配置泄漏
    $this->service = app(BalanceRechargeService::class);
});

/* ------------------------------------------------------------------ */
/* 赠送规则                                                            */
/* ------------------------------------------------------------------ */

test('赠送规则命中门槛最高的档位', function () {
    $this->config->set('payment.recharge_gift_rules', json_encode([
        ['amount' => 100, 'gift' => 10],
        ['amount' => 200, 'gift' => 30],
    ]));

    expect($this->service->giftFor('250.00'))->toBe('30.00')   // 命中 200 档
        ->and($this->service->giftFor('200.00'))->toBe('30.00')// 等于门槛也命中
        ->and($this->service->giftFor('150.00'))->toBe('10.00')// 命中 100 档
        ->and($this->service->giftFor('99.99'))->toBe('0.00'); // 未达门槛不送
});

test('赠送规则为空或非法 JSON 时不赠送', function () {
    $this->config->set('payment.recharge_gift_rules', '[]');
    expect($this->service->giftFor('500.00'))->toBe('0.00');

    $this->config->set('payment.recharge_gift_rules', 'not-json');
    expect($this->service->giftFor('500.00'))->toBe('0.00');
});

/* ------------------------------------------------------------------ */
/* 下单                                                                */
/* ------------------------------------------------------------------ */

test('发起充值创建充值单与支付单并返回 PayParams', function () {
    $result = $this->service->create($this->user->id, ['amount' => '100.00', 'channel' => 'mock']);

    expect($result['recharge']->status)->toBe(BalanceRecharge::STATUS_PENDING)
        ->and($result['recharge']->recharge_no)->toStartWith('RC')
        ->and($result['recharge']->expired_at)->not->toBeNull()
        ->and($result['payment']->biz_type)->toBe(Payment::BIZ_TYPE_RECHARGE)
        ->and($result['payment']->order_id)->toBeNull()
        ->and($result['payment']->biz_no)->toBe($result['recharge']->recharge_no)
        ->and($result['pay_params']['type'])->toBe('mock');
});

test('发起充值：低于最小金额被拒（40000）', function () {
    $this->service->create($this->user->id, ['amount' => '1.00', 'channel' => 'mock']);
})->throws(BusinessException::class, '单笔充值不得低于 10.00 元');

test('发起充值：高于单笔上限被拒（40000）', function () {
    $this->config->set('payment.recharge_max_single', '500.00');

    $this->service->create($this->user->id, ['amount' => '600.00', 'channel' => 'mock']);
})->throws(BusinessException::class, '单笔充值不得超过 500.00 元');

test('发起充值：单日累计超限被拒（40000）', function () {
    $this->config->set('payment.recharge_max_daily', '150.00');

    $this->service->create($this->user->id, ['amount' => '100.00', 'channel' => 'mock']);

    expect($this->service->dailyRecharged($this->user->id))->toBe('100.00');

    $this->service->create($this->user->id, ['amount' => '100.00', 'channel' => 'mock']);
})->throws(BusinessException::class, '单日累计充值不得超过 150.00 元');

test('发起充值：非数字金额被拒', function () {
    $this->service->create($this->user->id, ['amount' => 'abc', 'channel' => 'mock']);
})->throws(BusinessException::class, '请输入正确的充值金额');

test('发起充值：不支持余额支付', function () {
    $this->service->create($this->user->id, ['amount' => '100.00', 'channel' => 'balance']);
})->throws(BusinessException::class, '充值不支持的支付渠道');

test('发起充值：总开关关闭时拒绝', function () {
    $this->config->set('payment.recharge_enabled', '0');

    $this->service->create($this->user->id, ['amount' => '100.00', 'channel' => 'mock']);
})->throws(BusinessException::class, '余额充值功能暂未开放');

/* ------------------------------------------------------------------ */
/* 单日累计                                                            */
/* ------------------------------------------------------------------ */

test('单日累计只统计未失败/未关闭的充值单', function () {
    BalanceRecharge::create([
        'recharge_no' => 'RC-A', 'user_id' => $this->user->id, 'amount' => '100.00',
        'gift_amount' => '0.00', 'channel' => 'mock', 'status' => BalanceRecharge::STATUS_PENDING,
    ]);
    BalanceRecharge::create([
        'recharge_no' => 'RC-B', 'user_id' => $this->user->id, 'amount' => '200.00',
        'gift_amount' => '0.00', 'channel' => 'mock', 'status' => BalanceRecharge::STATUS_CLOSED,
    ]);
    BalanceRecharge::create([
        'recharge_no' => 'RC-C', 'user_id' => $this->user->id, 'amount' => '50.00',
        'gift_amount' => '0.00', 'channel' => 'mock', 'status' => BalanceRecharge::STATUS_FAILED,
    ]);

    // 仅 pending 的 100 计入（closed/failed 不占额度）
    expect($this->service->dailyRecharged($this->user->id))->toBe('100.00');
});

test('充值记录按用户隔离并支持状态筛选', function () {
    $other = createTestUser('recharge-other');
    BalanceRecharge::create([
        'recharge_no' => 'RC-ME', 'user_id' => $this->user->id, 'amount' => '100.00',
        'gift_amount' => '0.00', 'channel' => 'mock', 'status' => BalanceRecharge::STATUS_SUCCESS,
    ]);
    BalanceRecharge::create([
        'recharge_no' => 'RC-OTHER', 'user_id' => $other->id, 'amount' => '300.00',
        'gift_amount' => '0.00', 'channel' => 'mock', 'status' => BalanceRecharge::STATUS_SUCCESS,
    ]);

    $mine = $this->service->listForUser($this->user->id);
    expect($mine->total())->toBe(1)
        ->and($mine->items()[0]->recharge_no)->toBe('RC-ME');
});
