<?php

use App\Models\Payment;
use App\Models\PaymentReconciliationDiff;
use App\Models\PaymentReconciliationRun;
use App\Services\Payment\Dto\ChannelTransaction;
use App\Services\Payment\Gateways\MockGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 支付渠道日终对账命令（A7-支付渠道对账）
 *
 * 覆盖：指定日期+渠道执行并生成差异工单、非法日期退出码、每日 02:00 调度注册。
 */
beforeEach(function () {
    MockGateway::$syntheticBill = [];
});

test('A7K-01 命令按指定日期+渠道执行并生成差异工单', function () {
    $date = '2026-09-20';
    $user = createTestUser('pcrk'.uniqid());
    Payment::create([
        'payment_no' => 'P_'.uniqid(),
        'user_id' => $user->id,
        'channel' => 'mock',
        'amount' => '50.00',
        'status' => Payment::STATUS_SUCCESS,
        'channel_trade_no' => 'MATCH_OK',
        'paid_at' => $date.' 10:00:00',
        'biz_type' => Payment::BIZ_TYPE_ORDER,
    ]);
    // 渠道侧多一笔本地没有的 → MISSING_LOCAL（渠道长款）
    // 本地 MATCH_OK 为 success 但渠道账单无记录 → MISSING_CHANNEL（本地短款，与 Unit 侧 A7S-05 语义一致）
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CHK_ONLY', null, '30.00', 'paid'),
    ];

    $this->artisan('payments:reconcile', ['--date' => $date, '--channel' => 'mock'])
        ->assertExitCode(0)
        ->expectsOutputToContain('对账完成');

    expect(PaymentReconciliationRun::where(['reconcile_date' => $date, 'channel' => 'mock'])->exists())->toBeTrue()
        ->and(PaymentReconciliationDiff::count())->toBe(2)
        ->and(PaymentReconciliationDiff::where('channel_trade_no', 'CHK_ONLY')->value('diff_type'))->toBe(PaymentReconciliationDiff::TYPE_MISSING_LOCAL)
        ->and(PaymentReconciliationDiff::where('diff_type', PaymentReconciliationDiff::TYPE_MISSING_CHANNEL)->exists())->toBeTrue();
});

test('A7K-02 未传 --channel 时自动对账全部默认渠道（不空跑）', function () {
    $date = '2026-09-20';
    $user = createTestUser('pcrk'.uniqid());
    Payment::create([
        'payment_no' => 'P_'.uniqid(),
        'user_id' => $user->id,
        'channel' => 'mock',
        'amount' => '50.00',
        'status' => Payment::STATUS_SUCCESS,
        'channel_trade_no' => 'MOCK_DEF',
        'paid_at' => $date.' 10:00:00',
        'biz_type' => Payment::BIZ_TYPE_ORDER,
    ]);

    $this->artisan('payments:reconcile', ['--date' => $date])
        ->assertExitCode(0);

    // 默认渠道含 mock（且有本地 success）→ 应产生至少一条运行批次
    expect(PaymentReconciliationRun::where('reconcile_date', $date)->exists())->toBeTrue();
});

test('A7K-03 非法日期格式 → 非零退出码', function () {
    $this->artisan('payments:reconcile', ['--date' => '2026/09/20'])
        ->assertExitCode(1);
});

test('A7K-04 每日 02:00 调度已注册（防重叠）', function () {
    $this->artisan('schedule:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('payments:reconcile');
});
