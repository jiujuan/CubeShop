<?php

use App\Models\UserCheckin;
use App\Models\UserPointLog;
use App\Services\Member\CheckinService;
use App\Services\Member\PointsSettings;
use App\Support\Member\PointsRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 前台签到接口（会员成长计划 S2）
 *
 * 核心验收点：
 * - 同日只能一次（HTTP 409）
 * - 连续递增、断签归 1、第 8 天周期重置（D7）
 * - 签到真的发出积分，且积分流水带 biz_key 可幂等追溯
 *
 * ⚠️ 时间一律走 Carbon::setTestNow：签到只看「业务日期」，
 *    靠真实时间写测试会在跨零点时随机失败。
 */
beforeEach(function () {
    $this->user = createTestUser('ckbuyer');
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('ck')->plainTextToken];

    Carbon::setTestNow(Carbon::create(2026, 9, 20, 9, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

// ⚠️ 全局函数名必须够独特：Pest 逐个文件加载，重名会「Cannot redeclare」
if (! function_exists('checkinTravelDays')) {
    /** 推进到「基准日之后的第 n 天」的同一时刻 */
    function checkinTravelDays(int $days): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 9, 0, 0)->addDays($days));
    }
}

it('TC-CHECKIN-01 初始状态：未签到、连续 0 天、今日可得 5、明日可得 7', function () {
    $this->withHeaders($this->auth)->getJson('/api/checkin')
        ->assertOk()
        ->assertJsonPath('data.date', '2026-09-20')
        ->assertJsonPath('data.checked', false)
        ->assertJsonPath('data.streak', 0)
        ->assertJsonPath('data.today_points', 5)
        ->assertJsonPath('data.next_points', 7)
        ->assertJsonPath('data.total_days', 0)
        ->assertJsonPath('data.balance', 0)
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.milestone_days', [7]);
});

it('TC-CHECKIN-02 签到成功：发放 5 积分并写入带 biz_key 的流水', function () {
    $res = $this->withHeaders($this->auth)->postJson('/api/checkin');

    $res->assertOk()
        ->assertJsonPath('data.points', 5)
        ->assertJsonPath('data.streak', 1)
        ->assertJsonPath('data.date', '2026-09-20')
        // 返回体内直接带上签到后的完整状态，前端无需二次请求
        ->assertJsonPath('data.checked', true)
        ->assertJsonPath('data.balance', 5)
        ->assertJsonPath('data.total_days', 1);

    $log = UserPointLog::query()->where('user_id', $this->user->id)->firstOrFail();
    expect($log->type)->toBe(PointsRules::TYPE_SIGNIN)
        ->and($log->points)->toBe(5)
        ->and($log->biz_key)->toBe('checkin:'.$this->user->id.':2026-09-20')
        ->and($log->balance_after)->toBe(5);

    expect(UserCheckin::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('TC-CHECKIN-03 同日重复签到返回 409，且不重复发分', function () {
    $this->withHeaders($this->auth)->postJson('/api/checkin')->assertOk();

    $this->withHeaders($this->auth)->postJson('/api/checkin')
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);

    expect(UserPointLog::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(UserCheckin::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(app(CheckinService::class)->status($this->user->id)['balance'])->toBe(5);
});

it('TC-CHECKIN-04 次日接着签：连续天数递增到 2，奖励 7 分', function () {
    $this->withHeaders($this->auth)->postJson('/api/checkin')->assertOk();

    checkinTravelDays(1);

    // 链还活着（昨天签过），故未签到时 streak 仍显示 1、今日可得已是第 2 档
    $this->withHeaders($this->auth)->getJson('/api/checkin')
        ->assertOk()
        ->assertJsonPath('data.checked', false)
        ->assertJsonPath('data.streak', 1)
        ->assertJsonPath('data.today_points', 7);

    $this->withHeaders($this->auth)->postJson('/api/checkin')
        ->assertOk()
        ->assertJsonPath('data.streak', 2)
        ->assertJsonPath('data.points', 7)
        ->assertJsonPath('data.balance', 12);
});

it('TC-CHECKIN-05 断签：中间空一天后连续天数归 0，重新从 1 开始', function () {
    $this->withHeaders($this->auth)->postJson('/api/checkin')->assertOk();
    checkinTravelDays(1);
    $this->withHeaders($this->auth)->postJson('/api/checkin')->assertOk();

    // 跳过 9/22，直接到 9/23
    checkinTravelDays(3);

    $this->withHeaders($this->auth)->getJson('/api/checkin')
        ->assertOk()
        ->assertJsonPath('data.streak', 0)   // 链已断，不能还显示「连续 2 天」
        ->assertJsonPath('data.today_points', 5);

    $this->withHeaders($this->auth)->postJson('/api/checkin')
        ->assertOk()
        ->assertJsonPath('data.streak', 1)
        ->assertJsonPath('data.points', 5);
});

it('TC-CHECKIN-06 7 日循环：第 7 天拿里程碑 67 分，第 8 天周期重置回 5 分', function () {
    $service = app(CheckinService::class);

    $points = [];
    for ($day = 0; $day < 8; $day++) {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 9, 0, 0)->addDays($day));
        $row = $service->checkin($this->user->id);
        $points[] = [(int) $row->streak, (int) $row->points];
    }

    expect($points)->toBe([
        [1, 5], [2, 7], [3, 9], [4, 11], [5, 13], [6, 15], [7, 67], [1, 5],
    ]);

    // 8 天累计：5+7+9+11+13+15+67+5 = 132
    expect(app(CheckinService::class)->status($this->user->id)['balance'])->toBe(132);
});

it('TC-CHECKIN-07 签到未开启：POST 返回 400 且不发分', function () {
    app(PointsSettings::class)->updateSwitches(['points.enabled' => false]);

    $this->withHeaders($this->auth)->postJson('/api/checkin')
        ->assertStatus(400)
        ->assertJsonPath('code', 40000);

    expect(UserCheckin::query()->count())->toBe(0)
        ->and(UserPointLog::query()->count())->toBe(0);

    // 状态接口仍可用，只是标记不可用（前端据此隐藏按钮而不是报错）
    $this->withHeaders($this->auth)->getJson('/api/checkin')
        ->assertOk()
        ->assertJsonPath('data.available', false);
});

it('TC-CHECKIN-08 未登录访问签到接口返回 401', function () {
    $this->getJson('/api/checkin')->assertStatus(401);
    $this->postJson('/api/checkin')->assertStatus(401);
});

it('TC-CHECKIN-09 并发同日签到：unique 兜底只成功一次', function () {
    $service = app(CheckinService::class);

    // 第一次成功后再来一次，无论走前置查询还是撞唯一索引，都必须是 409 而不是脏数据
    $service->checkin($this->user->id);

    expect(fn () => $service->checkin($this->user->id))
        ->toThrow(\App\Exceptions\BusinessException::class, '该日期已签到');

    expect(UserCheckin::query()->count())->toBe(1)
        ->and(UserPointLog::query()->sum('points'))->toBe(5);
});
