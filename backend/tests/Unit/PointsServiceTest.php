<?php

use App\Exceptions\BusinessException;
use App\Models\UserPoint;
use App\Models\UserPointLog;
use App\Services\Member\PointsService;
use App\Support\Member\PointsRules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 积分账户服务（会员成长计划 S1）
 *
 * 覆盖：入账 / 出账 / 冻结→确认 / 冻结→释放 / 人工调整 / 幂等 / 余额不足 / 账户与流水一致性。
 * 冻结、确认、释放三个原语 S5 才接线，此处先把语义钉死，免得接线时被改坏。
 */
beforeEach(function () {
    $this->points = app(PointsService::class);
    $this->userId = createTestUser('pts')->id;
});

it('入账：可用积分增加并累计获得', function () {
    $log = $this->points->credit($this->userId, 100, PointsRules::TYPE_EARN, 'order', 1, 'order:1:earn', '消费返积分');

    expect($log->points)->toBe(100)
        ->and($log->balance_before)->toBe(0)
        ->and($log->balance_after)->toBe(100)
        ->and($log->frozen_points)->toBe(0);

    expect($this->points->balance($this->userId))->toBe(100);

    $account = UserPoint::query()->where('user_id', $this->userId)->first();
    expect($account->total_earn)->toBe(100)
        ->and($account->total_spend)->toBe(0);
});

it('出账：可用积分减少并累计消耗，不足时抛异常且不改余额', function () {
    $this->points->credit($this->userId, 100);
    $this->points->debit($this->userId, 30, PointsRules::TYPE_CONSUME, 'order', 2);

    expect($this->points->balance($this->userId))->toBe(70);
    expect(UserPoint::query()->where('user_id', $this->userId)->first()->total_spend)->toBe(30);

    // 余额不足：抛业务异常，余额与流水都不变
    try {
        $this->points->debit($this->userId, 999);
        $this->fail('积分不足却未抛异常');
    } catch (BusinessException $e) {
        expect($e->getMessage())->toContain('积分不足');
    }

    expect($this->points->balance($this->userId))->toBe(70)
        ->and(UserPointLog::query()->where('user_id', $this->userId)->count())->toBe(2);
});

it('幂等：同一 biz_key 只生效一次（回调重放不加分）', function () {
    $key = PointsRules::bizKey('order', 12, 'earn');

    $first = $this->points->credit($this->userId, 50, PointsRules::TYPE_EARN, 'order', 12, $key);
    $second = $this->points->credit($this->userId, 50, PointsRules::TYPE_EARN, 'order', 12, $key);

    expect($second->id)->toBe($first->id)
        ->and($this->points->balance($this->userId))->toBe(50)
        ->and(UserPointLog::query()->where('user_id', $this->userId)->count())->toBe(1);
});

it('冻结两段式：下单冻结 → 支付确认消耗', function () {
    $this->points->credit($this->userId, 100);

    // 冻结：可用 → 冻结
    $freeze = $this->points->freeze($this->userId, 40, 'order', 5, 'order:5:freeze');
    expect($freeze->points)->toBe(-40)
        ->and($freeze->frozen_points)->toBe(40)
        ->and($this->points->balance($this->userId))->toBe(60);

    $account = UserPoint::query()->where('user_id', $this->userId)->first();
    expect($account->frozen)->toBe(40)
        ->and($account->total_spend)->toBe(0); // 冻结不算消耗

    // 确认消耗：只减冻结，可用不变
    $confirm = $this->points->confirm($this->userId, 40, 'order', 5, 'order:5:consume');
    expect($confirm->points)->toBe(0)
        ->and($confirm->frozen_points)->toBe(-40)
        ->and($this->points->balance($this->userId))->toBe(60);

    $account->refresh();
    expect($account->frozen)->toBe(0)
        ->and($account->total_spend)->toBe(40)
        ->and($account->total)->toBe(60);
});

it('冻结释放：取消订单后积分原样回到可用，累计值不变', function () {
    $this->points->credit($this->userId, 100);
    $this->points->freeze($this->userId, 40, 'order', 6);
    $release = $this->points->release($this->userId, 40, 'order', 6);

    expect($release->points)->toBe(40)
        ->and($release->frozen_points)->toBe(-40)
        ->and($this->points->balance($this->userId))->toBe(100);

    $account = UserPoint::query()->where('user_id', $this->userId)->first();
    expect($account->frozen)->toBe(0)
        ->and($account->total_earn)->toBe(100)
        ->and($account->total_spend)->toBe(0); // 释放不是消耗
});

it('冻结不足：确认扣减超过冻结量时抛异常', function () {
    $this->points->credit($this->userId, 100);
    $this->points->freeze($this->userId, 40);

    expect(fn () => $this->points->confirm($this->userId, 41))
        ->toThrow(BusinessException::class, '冻结积分不足');
});

it('人工调整：正数加分负数减分，原因与操作人进流水', function () {
    $add = $this->points->adjust($this->userId, 20, '活动补偿', 7);
    expect($add->type)->toBe(PointsRules::TYPE_ADMIN_ADJUST)
        ->and($add->points)->toBe(20)
        ->and($add->remark)->toBe('活动补偿')
        ->and((int) $add->created_by)->toBe(7)
        ->and($add->balance_after)->toBe(20);

    $sub = $this->points->adjust($this->userId, -5, '误发回收', 7);
    expect($sub->points)->toBe(-5)
        ->and($sub->balance_after)->toBe(15);

    $account = UserPoint::query()->where('user_id', $this->userId)->first();
    expect($account->total_earn)->toBe(20)   // 加分计入累计获得
        ->and($account->total_spend)->toBe(5); // 减分计入累计消耗
});

it('人工调整：0 与超上限均被拒绝', function () {
    expect(fn () => $this->points->adjust($this->userId, 0, '无意义'))
        ->toThrow(BusinessException::class, '不能为 0');

    expect(fn () => $this->points->adjust($this->userId, PointsRules::ADJUST_MAX + 1, '太大'))
        ->toThrow(BusinessException::class, '单次调整不得超过');
});

it('入账/出账数量必须为正', function () {
    expect(fn () => $this->points->credit($this->userId, 0))->toThrow(BusinessException::class, '必须大于 0');
    expect(fn () => $this->points->credit($this->userId, -1))->toThrow(BusinessException::class, '必须大于 0');
});

it('账户与流水始终一致：可用余额等于流水 points 之和', function () {
    $this->points->credit($this->userId, 100);
    $this->points->debit($this->userId, 30);
    $this->points->freeze($this->userId, 20);
    $this->points->release($this->userId, 20);
    $this->points->credit($this->userId, 50, PointsRules::TYPE_SIGNIN);
    $this->points->adjust($this->userId, -10, '违规扣减');

    $sum = (int) UserPointLog::query()->where('user_id', $this->userId)->sum('points');
    $account = UserPoint::query()->where('user_id', $this->userId)->first();

    expect($account->balance)->toBe($sum)
        ->and($account->balance)->toBe(110)
        ->and($account->total_earn)->toBe(150)
        ->and($account->total_spend)->toBe(40);
});

it('账户不存在时自动创建，初始为 0', function () {
    expect($this->points->balance($this->userId))->toBe(0)
        ->and(UserPoint::query()->where('user_id', $this->userId)->exists())->toBeTrue();

    $summary = $this->points->summary($this->userId);
    expect($summary)->toBe([
        'balance' => 0, 'frozen' => 0, 'total' => 0, 'total_earn' => 0, 'total_spend' => 0,
    ]);
});

it('最近流水按时间倒序返回', function () {
    $this->points->credit($this->userId, 10);
    $this->points->credit($this->userId, 20);

    $logs = $this->points->logs($this->userId, 5);

    expect($logs)->toHaveCount(2)
        ->and($logs->first()->points)->toBe(20);
});
