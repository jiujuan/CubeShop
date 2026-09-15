<?php

use App\Models\SysUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    // 管理员
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminToken = $this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->adminToken];

    // 前台注册买家（对应「购买商品注册的用户」）
    $cap2 = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->buyerUsername = 'buyer'.uniqid();
    $this->buyerToken = $this->postJson('/api/auth/register', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyerToken];
    $this->buyer = SysUser::where('username', $this->buyerUsername)->first();
});

// 无 user.manage 权限：买家与运营账号均被拒 403
test('TC-USER-001 无权限用户访问用户管理被拒绝 403', function () {
    $this->getJson('/api/admin/users', $this->buyerAuth)->assertStatus(403);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $operatorToken = $this->postJson('/api/auth/login', [
        'username' => 'operator',
        'password' => 'Operator@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');

    $this->getJson('/api/admin/users', ['Authorization' => 'Bearer '.$operatorToken])->assertStatus(403);
});

// 列表：默认仅买家，含订单统计；keyword 搜索命中
test('TC-USER-002 用户列表默认买家账号且支持关键词搜索', function () {
    $list = $this->getJson('/api/admin/users', $this->adminAuth)->json();
    expect($list['code'])->toBe(0)
        ->and($list['data']['pagination']['total'])->toBeGreaterThanOrEqual(1);

    // 默认 role=customer：不包含后台账号 admin/operator
    $usernames = array_column($list['data']['list'], 'username');
    expect($usernames)->toContain($this->buyerUsername)
        ->and($usernames)->not->toContain('admin')
        ->and($usernames)->not->toContain('operator');

    // 关键词搜索
    $searched = $this->getJson('/api/admin/users?keyword='.$this->buyerUsername, $this->adminAuth)->json();
    expect($searched['data']['pagination']['total'])->toBe(1)
        ->and($searched['data']['list'][0]['username'])->toBe($this->buyerUsername);

    // 后台账号视图
    $admins = $this->getJson('/api/admin/users?role=admin', $this->adminAuth)->json();
    $adminNames = array_column($admins['data']['list'], 'username');
    expect($adminNames)->toContain('admin')->and($adminNames)->not->toContain($this->buyerUsername);
});

// 详情：基础资料 + 统计 + 最近订单
test('TC-USER-003 用户详情返回资料与最近订单', function () {
    $detail = $this->getJson('/api/admin/users/'.$this->buyer->id, $this->adminAuth)->json();
    expect($detail['code'])->toBe(0)
        ->and($detail['data']['username'])->toBe($this->buyerUsername)
        ->and($detail['data']['status'])->toBe(1)
        ->and($detail['data']['roles'])->toContain('customer')
        ->and($detail['data']['recent_orders'])->toBeArray();
});

// 编辑资料：成功更新 + 手机号冲突校验
test('TC-USER-004 编辑用户资料成功且校验唯一性', function () {
    $resp = $this->putJson('/api/admin/users/'.$this->buyer->id, [
        'nickname' => '改名用户',
        'phone' => '13900000001',
        'email' => 'buyer'.uniqid().'@test.com',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.nickname'))->toBe('改名用户')
        ->and($resp->json('data.phone'))->toBe('13900000001');

    // 另一个买家占用同一手机号 → 参数错误
    $other = createTestUser('otherbuyer');
    $conflict = $this->putJson('/api/admin/users/'.$other->id, [
        'phone' => '13900000001',
    ], $this->adminAuth);
    expect($conflict->json('code'))->toBe(40000);
});

// 禁用/启用：禁用强制下线，启用恢复
test('TC-USER-005 禁用用户吊销 Token 且启用恢复', function () {
    $resp = $this->putJson('/api/admin/users/'.$this->buyer->id.'/status', ['status' => 0], $this->adminAuth);
    expect($resp->json('code'))->toBe(0)->and($resp->json('data.status'))->toBe(0);

    // 已吊销 Token 访问 /auth/me → 401
    $this->getJson('/api/auth/me', $this->buyerAuth)->assertStatus(401);

    // 被禁用用户重新登录被拒
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $login = $this->postJson('/api/auth/login', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ]);
    expect($login->json('code'))->toBe(40009);

    $enable = $this->putJson('/api/admin/users/'.$this->buyer->id.'/status', ['status' => 1], $this->adminAuth);
    expect($enable->json('code'))->toBe(0)->and($enable->json('data.status'))->toBe(1);
});

// 保护规则：不能禁用自己；超级管理员不可被操作
test('TC-USER-006 保护规则：自身与超管不可禁用', function () {
    // super_admin id
    $adminId = SysUser::where('username', 'admin')->value('id');

    // 不能禁用自己（admin 本人，先命中超管保护规则 → 40003）
    $self = $this->putJson('/api/admin/users/'.$adminId.'/status', ['status' => 0], $this->adminAuth);
    expect($self->json('code'))->toBe(40003);

    // 超管账号不可被禁用/编辑（用另一个管理员身份操作）
    $guard = SysUser::create([
        'username' => 'guard'.uniqid(),
        'password' => Hash::make('Guard@1234'),
        'nickname' => '守卫账号',
        'status' => 1,
    ]);
    $guard->assignRole('operator');
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $guardToken = $this->postJson('/api/auth/login', [
        'username' => $guard->username,
        'password' => 'Guard@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');
    $guardAuth = ['Authorization' => 'Bearer '.$guardToken];

    $disableAdmin = $this->putJson('/api/admin/users/'.$adminId.'/status', ['status' => 0], $guardAuth);
    expect($disableAdmin->json('code'))->toBe(40003);

    $editAdmin = $this->putJson('/api/admin/users/'.$adminId, ['nickname' => '改名'], $guardAuth);
    expect($editAdmin->json('code'))->toBe(40003);
});
