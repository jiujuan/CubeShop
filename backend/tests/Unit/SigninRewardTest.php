<?php

use App\Support\Member\SigninReward;

/**
 * 签到奖励算法（会员成长计划 S2）
 *
 * 纯函数单测：不启数据库、不读配置，规则参数显式传入。
 * 这里钉死的是「规则本身」，配置读取与写入链路在 CheckinApiTest 覆盖。
 */
// ⚠️ 全局函数名必须够独特：Pest 逐个文件加载，重名会「Cannot redeclare」
if (! function_exists('signinTestCfg')) {
    function signinTestCfg(int $base = 5, int $step = 2, int $max = 30, array $bonus = ['7' => 50]): array
    {
        return ['base' => $base, 'step' => $step, 'max' => $max, 'bonus' => $bonus];
    }
}

it('TC-SIGNIN-ALGO-01 连续天数 7 日循环：第 8 天归 1', function () {
    expect(SigninReward::nextStreak(0))->toBe(1)
        ->and(SigninReward::nextStreak(1))->toBe(2)
        ->and(SigninReward::nextStreak(6))->toBe(7)
        ->and(SigninReward::nextStreak(7))->toBe(1)   // D7：周期重置，不是继续累加
        ->and(SigninReward::nextStreak(-3))->toBe(1); // 脏数据兜底
});

it('TC-SIGNIN-ALGO-02 递增奖励：base + (streak-1)×step，被 max 截断', function () {
    expect(SigninReward::progressive(1, 5, 2, 30))->toBe(5)
        ->and(SigninReward::progressive(2, 5, 2, 30))->toBe(7)
        ->and(SigninReward::progressive(7, 5, 2, 30))->toBe(17)
        // 20 天本该 5+19×2=43，被 max=30 截断
        ->and(SigninReward::progressive(20, 5, 2, 30))->toBe(30)
        ->and(SigninReward::progressive(0, 5, 2, 30))->toBe(0);
});

it('TC-SIGNIN-ALGO-03 里程碑奖励只在指定天数生效', function () {
    expect(SigninReward::bonus(7, ['7' => 50]))->toBe(50)
        ->and(SigninReward::bonus(6, ['7' => 50]))->toBe(0)
        ->and(SigninReward::bonus(7, []))->toBe(0)
        // 键是字符串（JSON 反序列化后必然如此），仍要能命中
        ->and(SigninReward::bonus(3, ['3' => '20']))->toBe(20);
});

it('TC-SIGNIN-ALGO-04 实发积分 = 递增 + 里程碑', function () {
    expect(SigninReward::pointsFor(1, signinTestCfg()))->toBe(5)
        ->and(SigninReward::pointsFor(2, signinTestCfg()))->toBe(7)
        ->and(SigninReward::pointsFor(7, signinTestCfg()))->toBe(67); // 17 + 50

    // 无里程碑配置时只剩递增
    expect(SigninReward::pointsFor(7, signinTestCfg(5, 2, 30, [])))->toBe(17);
});

it('TC-SIGNIN-ALGO-05 一整个周期（1~8 天）的奖励序列', function () {
    $seq = [];
    $streak = 0;
    for ($day = 1; $day <= 8; $day++) {
        $streak = SigninReward::nextStreak($streak);
        $seq[] = SigninReward::pointsFor($streak, signinTestCfg());
    }

    // 第 7 天是里程碑高峰 67，第 8 天周期重置回 5 —— 这是有意设计，不是 bug
    expect($seq)->toBe([5, 7, 9, 11, 13, 15, 67, 5]);
});

it('TC-SIGNIN-ALGO-06 里程碑天数升序输出（前端进度条用）', function () {
    expect(SigninReward::milestoneDays(['7' => 50]))->toBe([7])
        ->and(SigninReward::milestoneDays(['7' => 50, '3' => 20]))->toBe([3, 7])
        ->and(SigninReward::milestoneDays([]))->toBe([]);
});

it('TC-SIGNIN-ALGO-07 max 低于 base 时以 max 为准，不会负增长', function () {
    expect(SigninReward::progressive(3, 10, 5, 4))->toBe(4)
        ->and(SigninReward::progressive(1, 10, 5, 4))->toBe(4);
});
