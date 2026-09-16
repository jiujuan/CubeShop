<?php

use App\Models\SysUser;
use App\Models\User;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 买家个人资料接口（/user/profile）
 *
 * V1.1 用户表拆分后重点回归：
 *  - 买家资料读写落在 users 表
 *  - 唯一性校验按身份落到对应表（买家 users / 管理员 sys_user）
 *  - 买家不参与 spatie，roles / permissions 返回空数组
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->username = 'prof'.uniqid();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => $this->username,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');

    $this->auth = ['Authorization' => 'Bearer '.$this->token];
    $this->buyer = User::where('username', $this->username)->firstOrFail();
});

test('TC-PROF-001 买家资料返回空角色与空权限（不参与 spatie）', function () {
    $resp = $this->getJson('/api/user/profile', $this->auth);

    $resp->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('data.username', $this->username)
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.permissions', []);
});

test('TC-PROF-002 买家更新资料写入 users 表', function () {
    $resp = $this->putJson('/api/user/profile', [
        'nickname' => '新昵称',
        'phone' => '13700000001',
    ], $this->auth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.nickname'))->toBe('新昵称');

    $fresh = User::find($this->buyer->id);
    expect($fresh->nickname)->toBe('新昵称')
        ->and($fresh->phone)->toBe('13700000001');
});

test('TC-PROF-003 买家手机号唯一性按 users 表校验', function () {
    // 与其他买家冲突 → 拒绝
    $other = createTestUser('other');
    $other->forceFill(['phone' => '13700000002'])->save();

    $conflict = $this->putJson('/api/user/profile', ['phone' => '13700000002'], $this->auth);
    expect($conflict->json('code'))->toBe(40000);

    // 与后台管理员同号不冲突（两套账号体系独立）
    SysUser::where('username', 'admin')->firstOrFail()->forceFill(['phone' => '13700000003'])->save();

    $ok = $this->putJson('/api/user/profile', ['phone' => '13700000003'], $this->auth);
    expect($ok->json('code'))->toBe(0);
    expect(User::find($this->buyer->id)->phone)->toBe('13700000003');
});

test('TC-PROF-004 买家资料变更写入带 customer 身份的操作日志', function () {
    $this->putJson('/api/user/profile', ['nickname' => '日志昵称'], $this->auth)->assertOk();

    $log = \App\Models\SysOperationLog::where('module', 'user')
        ->where('action', 'update_profile')
        ->where('user_id', $this->buyer->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe('customer')
        ->and($log->target_type)->toBe('users');
});

test('TC-PROF-005 买家登录写入带 customer 身份的操作日志', function () {
    $cap = app(CaptchaService::class)->generate();
    $this->postJson('/api/auth/login', [
        'username' => $this->username,
        'password' => 'Test@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->assertOk();

    $log = \App\Models\SysOperationLog::where('module', 'auth')
        ->where('action', 'login')
        ->where('user_id', $this->buyer->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()->and($log->actor_type)->toBe('customer');
});
