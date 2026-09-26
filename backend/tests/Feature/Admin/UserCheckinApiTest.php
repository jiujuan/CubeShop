<?php

use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\UserCheckin;
use App\Models\UserPointLog;
use App\Services\Common\CaptchaService;
use App\Services\Member\CheckinService;
use App\Support\Member\PointsRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * 后台补签接口（会员成长计划 S2 / D8）
 *
 * 补签会真实发放积分，故权限挂 member.manage —— 与积分调整同档。
 * 客服角色（cs_agent）没有该权限，用来验证「补不了」。
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

    SysUser::create([
        'username' => 'csnockin',
        'password' => Hash::make('Cs@12345'),
        'nickname' => '客服',
        'status' => 1,
    ])->syncRoles(['cs_agent']);

    $this->csAuth = ['Authorization' => 'Bearer '.$login('csnockin', 'Cs@12345')];

    $this->buyer = createTestUser('bfbuyer');

    Carbon::setTestNow(Carbon::create(2026, 9, 20, 9, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('TC-BACKFILL-01 无 member.manage 权限的客服补签返回 403', function () {
    $this->withHeaders($this->csAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-19'])
        ->assertStatus(403);

    expect(UserCheckin::query()->count())->toBe(0);
});

it('TC-BACKFILL-02 补签昨天：发放积分、标记补签、写操作日志', function () {
    $res = $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-19']);

    $res->assertOk()
        ->assertJsonPath('data.date', '2026-09-19')
        ->assertJsonPath('data.streak', 1)
        ->assertJsonPath('data.points', 5)
        ->assertJsonPath('data.is_backfill', true);

    $row = UserCheckin::query()->where('user_id', $this->buyer->id)->firstOrFail();
    expect((bool) $row->is_backfill)->toBeTrue()
        ->and((int) $row->created_by)->toBeGreaterThan(0);

    $log = UserPointLog::query()->where('user_id', $this->buyer->id)->firstOrFail();
    expect($log->type)->toBe(PointsRules::TYPE_SIGNIN)
        ->and($log->points)->toBe(5)
        ->and($log->biz_key)->toBe('checkin:'.$this->buyer->id.':2026-09-19');

    expect(SysOperationLog::query()
        ->where('action', 'backfill_checkin')
        ->where('target_type', 'user_checkins')
        ->exists(),
    )->toBeTrue();
});

it('TC-BACKFILL-03 已签到的日期再补签返回 409，不重复发分', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-19'])
        ->assertOk();

    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-19'])
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);

    expect(UserPointLog::query()->count())->toBe(1)
        ->and(app(CheckinService::class)->status($this->buyer->id)['balance'])->toBe(5);
});

it('TC-BACKFILL-04 补签今天或未来日期被校验拒绝（422）', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-20'])
        ->assertStatus(422);

    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-21'])
        ->assertStatus(422);

    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026/09/18'])
        ->assertStatus(422);

    expect(UserCheckin::query()->count())->toBe(0);
});

it('TC-BACKFILL-05 补签空洞后，后续日期的连续链被重算（积分不追溯补发）', function () {
    $service = app(CheckinService::class);

    // 9/18 签（第 1 天），跳过 9/19，9/20 再签 —— 此时 9/20 会从 1 重新开始
    $service->backfill($this->buyer->id, '2026-09-18', 1);
    $service->checkin($this->buyer->id);
    expect((int) UserCheckin::query()->where('checkin_date', '2026-09-20')->value('streak'))->toBe(1);

    // 补上 9/19 这个空洞：它接在 9/18 后面成为第 2 天，9/20 顺推为第 3 天
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/checkins/backfill', ['date' => '2026-09-19'])
        ->assertOk()
        ->assertJsonPath('data.streak', 2)
        ->assertJsonPath('data.points', 7);

    expect((int) UserCheckin::query()->where('checkin_date', '2026-09-19')->value('streak'))->toBe(2)
        ->and((int) UserCheckin::query()->where('checkin_date', '2026-09-20')->value('streak'))->toBe(3)
        // ⚠️ 9/20 的实发积分仍是当时按第 1 天算的 5，不追溯补成第 3 档的 9
        ->and((int) UserCheckin::query()->where('checkin_date', '2026-09-20')->value('points'))->toBe(5);

    // 总积分 = 5(9/18) + 7(9/19 补) + 5(9/20) = 17
    expect($service->status($this->buyer->id)['balance'])->toBe(17);
});

it('TC-BACKFILL-06 用户不存在返回 404', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/999999/checkins/backfill', ['date' => '2026-09-19'])
        ->assertStatus(404);
});
