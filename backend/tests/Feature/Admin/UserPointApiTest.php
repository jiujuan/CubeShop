<?php

use App\Models\SysOperationLog;
use App\Models\SysUser;
use App\Models\UserPointLog;
use App\Services\Common\CaptchaService;
use App\Support\Member\PointsRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * 后台会员积分接口（会员成长计划 S1）
 *
 * 权限两档：
 * - member.view：GET /admin/users/{id}/points
 * - member.manage：POST /admin/users/{id}/points/adjust
 *
 * 客服角色（cs_agent）两个权限都没有，用来验证「看不到也改不了」。
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

    // 客服：只持有 cs.*，不含 member.view / member.manage
    SysUser::create([
        'username' => 'csnopoint',
        'password' => Hash::make('Cs@12345'),
        'nickname' => '客服',
        'status' => 1,
    ])->syncRoles(['cs_agent']);

    $this->csAuth = ['Authorization' => 'Bearer '.$login('csnopoint', 'Cs@12345')];

    $this->buyer = createTestUser('ptsbuyer');
});

it('查看积分账户：初始为 0 且无流水', function () {
    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/users/'.$this->buyer->id.'/points');

    $res->assertOk()
        ->assertJsonPath('data.user_id', $this->buyer->id)
        ->assertJsonPath('data.account.balance', 0)
        ->assertJsonPath('data.account.frozen', 0)
        ->assertJsonPath('data.account.total_earn', 0)
        ->assertJsonPath('data.logs', []);
});

it('运营可人工加分：余额与流水同步，原因进备注', function () {
    $res = $this->withHeaders($this->operatorAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/points/adjust', [
            'points' => 50,
            'reason' => '活动补偿',
        ]);

    $res->assertOk()
        ->assertJsonPath('data.account.balance', 50)
        ->assertJsonPath('data.log.type', 'admin_adjust')
        ->assertJsonPath('data.log.type_label', '后台调整')
        ->assertJsonPath('data.log.points', 50)
        ->assertJsonPath('data.log.remark', '活动补偿');

    // 详情页能看到这条流水
    $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/users/'.$this->buyer->id.'/points')
        ->assertOk()
        ->assertJsonPath('data.account.balance', 50)
        ->assertJsonCount(1, 'data.logs');
});

it('人工减分超过可用积分时拒绝', function () {
    $this->withHeaders($this->operatorAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/points/adjust', ['points' => 30, 'reason' => '先加点'])
        ->assertOk();

    $this->withHeaders($this->operatorAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/points/adjust', ['points' => -31, 'reason' => '扣太多'])
        ->assertStatus(400)
        ->assertJsonPath('message', '可用积分不足');

    expect(UserPointLog::query()->where('user_id', $this->buyer->id)->count())->toBe(1);
});

it('调整参数校验：0 值、超上限、缺原因均 422', function () {
    $url = '/api/admin/users/'.$this->buyer->id.'/points/adjust';

    $this->withHeaders($this->operatorAuth)->postJson($url, ['points' => 0, 'reason' => 'x'])->assertStatus(422);
    $this->withHeaders($this->operatorAuth)
        ->postJson($url, ['points' => PointsRules::ADJUST_MAX + 1, 'reason' => 'x'])
        ->assertStatus(422);
    $this->withHeaders($this->operatorAuth)->postJson($url, ['points' => 10])->assertStatus(422);

    expect(UserPointLog::query()->where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('人工调整写入操作日志且可追溯', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/points/adjust', ['points' => 20, 'reason' => '投诉补偿'])
        ->assertOk();

    $log = SysOperationLog::query()->where('action', 'adjust_points')->first();

    expect($log)->not->toBeNull()
        ->and($log->module)->toBe('member')
        ->and($log->target_type)->toBe('users')
        ->and($log->target_id)->toBe($this->buyer->id)
        ->and($log->content)->toContain('投诉补偿')
        ->and($log->content)->toContain('增加');
});

it('无 member.view 的客服：查看积分 403', function () {
    $this->withHeaders($this->csAuth)
        ->getJson('/api/admin/users/'.$this->buyer->id.'/points')
        ->assertStatus(403);
});

it('无 member.manage 的客服：调整积分 403', function () {
    $this->withHeaders($this->csAuth)
        ->postJson('/api/admin/users/'.$this->buyer->id.'/points/adjust', ['points' => 10, 'reason' => '试一下'])
        ->assertStatus(403);

    expect(UserPointLog::query()->where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('用户不存在时 404', function () {
    $this->withHeaders($this->adminAuth)->getJson('/api/admin/users/999999/points')->assertStatus(404);
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/users/999999/points/adjust', ['points' => 1, 'reason' => 'x'])
        ->assertStatus(404);
});
