<?php

use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\PaymentReconciliationDiff;
use App\Models\PaymentReconciliationRun;
use App\Models\SysOperationLog;
use App\Services\Payment\Dto\ChannelTransaction;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\PaymentReconcileService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 支付渠道日终对账引擎（A7-支付渠道对账）单元测试
 *
 * 覆盖：四种差异类型的识别、幂等重跑、处置（resolve/ignore）幂等与审计。
 * 渠道侧来源通过 MockGateway 合成账单 / 注入合成账单，余额渠道走日志源降级。
 */

beforeEach(function () {
    // 静态合成账单必须在每个用例前清空，避免跨用例串味
    MockGateway::$syntheticBill = [];
});

/** 建一笔本地 success 支付单（带合法 user_id 以满足外键） */
function pcrPayment(string $channel, string $date, array $overrides = []): Payment
{
    $user = createTestUser('pcr'.uniqid());

    return Payment::create(array_merge([
        'payment_no' => 'P_'.strtoupper(uniqid()),
        'user_id' => $user->id,
        'channel' => $channel,
        'amount' => '100.00',
        'status' => Payment::STATUS_SUCCESS,
        'channel_trade_no' => 'T_'.uniqid(),
        'paid_at' => $date.' 10:00:00',
        'biz_type' => Payment::BIZ_TYPE_ORDER,
    ], $overrides));
}

test('A7S-01 全匹配无差异：不建单、批次 done、matched=1', function () {
    $date = '2026-09-20';
    pcrPayment('mock', $date, ['channel_trade_no' => 'MATCH1', 'amount' => '88.00']);

    // mock 默认合成账单 = 本地 success 支付单（金额一致）→ 完全匹配
    $res = app(PaymentReconcileService::class)->run($date, ['mock']);

    expect($res['total_diff'])->toBe(0)
        ->and(PaymentReconciliationDiff::count())->toBe(0);

    $run = PaymentReconciliationRun::where(['reconcile_date' => $date, 'channel' => 'mock'])->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(PaymentReconciliationRun::STATUS_DONE)
        ->and($run->local_count)->toBe(1)
        ->and($run->channel_count)->toBe(1)
        ->and($run->matched_count)->toBe(1);
});

test('A7S-02 渠道有、本地无 → MISSING_LOCAL（漏单/渠道长款）', function () {
    $date = '2026-09-20';
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '30.00', 'paid'),
    ];

    $res = app(PaymentReconcileService::class)->run($date, ['mock']);

    expect($res['total_diff'])->toBe(1);
    $diff = PaymentReconciliationDiff::first();
    expect($diff->diff_type)->toBe(PaymentReconciliationDiff::TYPE_MISSING_LOCAL)
        ->and($diff->channel_trade_no)->toBe('CH_ONLY_1')
        ->and($diff->channel_amount)->toBe('30.00')
        ->and($diff->payment_no)->toBeNull()
        ->and($diff->status)->toBe(PaymentReconciliationDiff::STATUS_PENDING);

    $run = PaymentReconciliationRun::where(['reconcile_date' => $date, 'channel' => 'mock'])->first();
    expect($run->status)->toBe(PaymentReconciliationRun::STATUS_PARTIAL)
        ->and($run->local_count)->toBe(0)
        ->and($run->channel_count)->toBe(1);
});

test('A7S-03 双方都有但金额不一致 → AMOUNT_MISMATCH（长短款）', function () {
    $date = '2026-09-20';
    $p = pcrPayment('mock', $date, ['channel_trade_no' => 'AMT_1', 'amount' => '99.00']);
    MockGateway::$syntheticBill = [
        new ChannelTransaction('AMT_1', $p->payment_no, '100.00', 'paid'),
    ];

    app(PaymentReconcileService::class)->run($date, ['mock']);

    $diff = PaymentReconciliationDiff::first();
    expect($diff->diff_type)->toBe(PaymentReconciliationDiff::TYPE_AMOUNT_MISMATCH)
        ->and($diff->local_amount)->toBe('99.00')
        ->and($diff->channel_amount)->toBe('100.00')
        ->and($diff->payment_no)->toBe($p->payment_no)
        ->and($diff->status)->toBe(PaymentReconciliationDiff::STATUS_PENDING);
});

test('A7S-04 同交易号多次成功回调 → DUPLICATE_CALLBACK（重复回调）', function () {
    $date = '2026-09-20';
    $p = pcrPayment('mock', $date, ['channel_trade_no' => 'DUP_1', 'amount' => '50.00']);
    MockGateway::$syntheticBill = [
        new ChannelTransaction('DUP_1', $p->payment_no, '50.00', 'paid'),
        new ChannelTransaction('DUP_1', $p->payment_no, '50.00', 'paid'),
    ];

    app(PaymentReconcileService::class)->run($date, ['mock']);

    $diffs = PaymentReconciliationDiff::all();
    expect($diffs)->toHaveCount(1)
        ->and($diffs[0]->diff_type)->toBe(PaymentReconciliationDiff::TYPE_DUPLICATE_CALLBACK)
        ->and($diffs[0]->channel_trade_no)->toBe('DUP_1');

    $run = PaymentReconciliationRun::where(['reconcile_date' => $date, 'channel' => 'mock'])->first();
    // 本地那笔仍算匹配到渠道（去重后）
    expect($run->matched_count)->toBe(1)
        ->and($run->local_count)->toBe(1)
        ->and($run->channel_count)->toBe(2);
});

test('A7S-05 本地 success 但渠道无记录 → MISSING_CHANNEL（本地短款/资金风险）', function () {
    $date = '2026-09-20';
    // 余额渠道无远程账单 → 引擎降级日志源；本地该笔无成功回调日志 → 视为渠道侧缺失
    pcrPayment('balance', $date, ['channel_trade_no' => null, 'payment_no' => 'BAL_1', 'amount' => '200.00']);

    $res = app(PaymentReconcileService::class)->run($date, ['balance']);

    expect($res['total_diff'])->toBe(1);
    $diff = PaymentReconciliationDiff::first();
    expect($diff->diff_type)->toBe(PaymentReconciliationDiff::TYPE_MISSING_CHANNEL)
        ->and($diff->payment_no)->toBe('BAL_1')
        ->and($diff->local_amount)->toBe('200.00')
        ->and($diff->channel_amount)->toBeNull()
        ->and($diff->status)->toBe(PaymentReconciliationDiff::STATUS_PENDING);

    $run = PaymentReconciliationRun::where(['reconcile_date' => $date, 'channel' => 'balance'])->first();
    expect($run->matched_count)->toBe(0);
});

test('A7S-06 幂等重跑：同一差异只保留一条 pending（每日重跑不重复开单）', function () {
    $date = '2026-09-20';
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '30.00', 'paid'),
    ];

    $svc = app(PaymentReconcileService::class);
    $svc->run($date, ['mock']);
    expect(PaymentReconciliationDiff::count())->toBe(1);

    // 期间金额变了（渠道侧金额更新），重跑应刷新同一 pending 而非新增
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '35.00', 'paid'),
    ];
    $svc->run($date, ['mock']);

    expect(PaymentReconciliationDiff::count())->toBe(1);
    $diff = PaymentReconciliationDiff::first();
    expect($diff->channel_amount)->toBe('35.00')
        ->and($diff->status)->toBe(PaymentReconciliationDiff::STATUS_PENDING);
});

test('A7S-07 已处置的差异重跑不重开（保留 resolved/ignored）', function () {
    $date = '2026-09-20';
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '30.00', 'paid'),
    ];
    $svc = app(PaymentReconcileService::class);
    $svc->run($date, ['mock']);

    $id = (int) PaymentReconciliationDiff::first()->id;
    $svc->resolve($id, 1, '已补单');

    // 重跑仍应只有 1 条 diff（已处置的那条不被覆盖）
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '30.00', 'paid'),
    ];
    $svc->run($date, ['mock']);

    expect(PaymentReconciliationDiff::count())->toBe(1);
    expect(PaymentReconciliationDiff::first()->status)->toBe(PaymentReconciliationDiff::STATUS_RESOLVED);
});

test('A7S-08 resolve 置 resolved 并落审计（处置人 + 类型）', function () {
    $date = '2026-09-20';
    $p = pcrPayment('mock', $date, ['channel_trade_no' => 'AMT_1', 'amount' => '99.00']);
    MockGateway::$syntheticBill = [
        new ChannelTransaction('AMT_1', $p->payment_no, '100.00', 'paid'),
    ];
    $svc = app(PaymentReconcileService::class);
    $svc->run($date, ['mock']);

    $id = (int) PaymentReconciliationDiff::first()->id;
    $handled = $svc->resolve($id, 7, '已调账');

    expect($handled->status)->toBe(PaymentReconciliationDiff::STATUS_RESOLVED)
        ->and($handled->handled_by)->toBe(7)
        ->and($handled->handle_remark)->toBe('已调账')
        ->and($handled->handled_at)->not->toBeNull();

    $log = SysOperationLog::where('module', 'payment_reconcile')
        ->where('action', 'diff_resolved')
        ->where('target_id', $id)
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe(7)
        ->and(json_decode($log->content, true)['payment_no'])->toBe($p->payment_no);
});

test('A7S-09 ignore 置 ignored 并落审计', function () {
    $date = '2026-09-20';
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '30.00', 'paid'),
    ];
    $svc = app(PaymentReconcileService::class);
    $svc->run($date, ['mock']);

    $id = (int) PaymentReconciliationDiff::first()->id;
    $handled = $svc->ignore($id, 7, '已知在途，关单');

    expect($handled->status)->toBe(PaymentReconciliationDiff::STATUS_IGNORED);

    $log = SysOperationLog::where('module', 'payment_reconcile')
        ->where('action', 'diff_ignored')
        ->where('target_id', $id)
        ->first();
    expect($log)->not->toBeNull();
});

test('A7S-10 处置幂等：已处置的差异重复 resolve/ignore 抛冲突异常', function () {
    $date = '2026-09-20';
    MockGateway::$syntheticBill = [
        new ChannelTransaction('CH_ONLY_1', null, '30.00', 'paid'),
    ];
    $svc = app(PaymentReconcileService::class);
    $svc->run($date, ['mock']);
    $id = (int) PaymentReconciliationDiff::first()->id;
    $svc->resolve($id, 1);

    expect(fn () => $svc->resolve($id, 1))->toThrow(BusinessException::class)
        ->and(fn () => $svc->ignore($id, 1))->toThrow(BusinessException::class);
});

test('A7S-11 处置不存在的差异 → 404', function () {
    $svc = app(PaymentReconcileService::class);
    expect(fn () => $svc->resolve(999999, 1))->toThrow(BusinessException::class)
        ->and(fn () => $svc->ignore(999999, 1))->toThrow(BusinessException::class);
});
