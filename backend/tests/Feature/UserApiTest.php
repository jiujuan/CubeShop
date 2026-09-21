<?php

use App\Models\SysUser;
use App\Models\User;
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

    // 前台注册买家（写入 users 表，V1.1 用户表拆分后不参与 spatie 角色）
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
    $this->buyer = User::where('username', $this->buyerUsername)->first();
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

// 列表：仅买家（管理员属账号管理），含订单统计；keyword 搜索命中
test('TC-USER-002 用户列表仅含买家账号且支持关键词搜索', function () {
    $list = $this->getJson('/api/admin/users', $this->adminAuth)->json();
    expect($list['code'])->toBe(0)
        ->and($list['data']['pagination']['total'])->toBeGreaterThanOrEqual(1);

    // 仅买家：不包含后台账号 admin/operator
    $usernames = array_column($list['data']['list'], 'username');
    expect($usernames)->toContain($this->buyerUsername)
        ->and($usernames)->not->toContain('admin')
        ->and($usernames)->not->toContain('operator');

    // 关键词搜索
    $searched = $this->getJson('/api/admin/users?keyword='.$this->buyerUsername, $this->adminAuth)->json();
    expect($searched['data']['pagination']['total'])->toBe(1)
        ->and($searched['data']['list'][0]['username'])->toBe($this->buyerUsername);
});

// 详情：基础资料 + 统计 + 最近订单
test('TC-USER-003 用户详情返回资料与最近订单', function () {
    $detail = $this->getJson('/api/admin/users/'.$this->buyer->id, $this->adminAuth)->json();
    expect($detail['code'])->toBe(0)
        ->and($detail['data']['username'])->toBe($this->buyerUsername)
        ->and($detail['data']['status'])->toBe(1)
        // 买家不参与 spatie 权限体系，角色为空
        ->and($detail['data']['roles'])->toBe([])
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

// 账号隔离：买家管理接口只作用于 users，绝不写 sys_user（防两张表 ID 撞号互相误伤）
test('TC-USER-006 买家管理接口只作用于 users，不影响同 ID 的管理员', function () {
    $adminId = (int) SysUser::where('username', 'admin')->value('id');
    $adminNickname = SysUser::find($adminId)->nickname;
    $adminStatus = (int) SysUser::find($adminId)->status;

    // 用管理员的 ID 调用买家管理接口
    $disable = $this->putJson('/api/admin/users/'.$adminId.'/status', ['status' => 0], $this->adminAuth);
    $edit = $this->putJson('/api/admin/users/'.$adminId, ['nickname' => '改名'], $this->adminAuth);
    $detail = $this->getJson('/api/admin/users/'.$adminId, $this->adminAuth);

    if (User::whereKey($adminId)->exists()) {
        // 命中 users 表中同 ID 的买家：操作落在买家身上
        expect($disable->json('code'))->toBe(0)
            ->and((int) User::find($adminId)->status)->toBe(0)
            ->and(User::find($adminId)->nickname)->toBe('改名');
    } else {
        // users 表中无此 ID → 用户不存在，不会回退去查 sys_user
        expect($disable->json('code'))->toBe(40004)
            ->and($edit->json('code'))->toBe(40004)
            ->and($detail->json('code'))->toBe(40004);
    }

    // 关键断言：管理员账号的资料与状态始终未被触碰
    $admin = SysUser::find($adminId);
    expect((int) $admin->status)->toBe($adminStatus)
        ->and($admin->nickname)->toBe($adminNickname);
});

// 注册守卫：买家不得占用后台账号的用户名
test('TC-USER-007 买家注册不得占用管理员用户名', function () {
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $resp = $this->postJson('/api/auth/register', [
        'username' => 'admin',
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ]);

    expect($resp->json('code'))->toBe(40000);
    expect(User::where('username', 'admin')->exists())->toBeFalse();
});

// 登录分流：管理员与买家同一入口，各自查自己的表
test('TC-USER-008 登录按 admin 优先 / 买家兜底正确分流', function () {
    // 管理员登录
    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $adminLogin = $this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ]);
    expect($adminLogin->json('code'))->toBe(0)
        ->and($adminLogin->json('data.user.roles'))->toContain('super_admin');

    // 买家登录
    $cap2 = app(\App\Services\Common\CaptchaService::class)->generate();
    $buyerLogin = $this->postJson('/api/auth/login', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'captcha_id' => $cap2['captcha_id'],
        'captcha_code' => $cap2['debug_code'],
    ]);
    expect($buyerLogin->json('code'))->toBe(0)
        ->and($buyerLogin->json('data.user.roles'))->toBe([]);
});

// 管理员重置买家密码：成功 + 新密码可登录 + 旧密码失效 + 强制重新登录
test('TC-USER-009 管理员重置密码后新密码生效且旧密码失效', function () {
    $resp = $this->putJson('/api/admin/users/'.$this->buyer->id.'/password', [
        'password' => 'NewPass@2024',
        'password_confirmation' => 'NewPass@2024',
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('message'))->toContain('重置');

    // 新密码可登录
    $cap1 = app(\App\Services\Common\CaptchaService::class)->generate();
    $ok = $this->postJson('/api/auth/login', [
        'username' => $this->buyerUsername,
        'password' => 'NewPass@2024',
        'captcha_id' => $cap1['captcha_id'],
        'captcha_code' => $cap1['debug_code'],
    ]);
    expect($ok->json('code'))->toBe(0);

    // 旧密码失效
    $cap2 = app(\App\Services\Common\CaptchaService::class)->generate();
    $bad = $this->postJson('/api/auth/login', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'captcha_id' => $cap2['captcha_id'],
        'captcha_code' => $cap2['debug_code'],
    ]);
    expect($bad->json('code'))->toBe(40000);

    // 旧 Token 被吊销 → 401
    $this->getJson('/api/auth/me', $this->buyerAuth)->assertStatus(401);
});

// 重置密码校验：长度/复杂度/弱口令/两次不一致
test('TC-USER-010 重置密码参数校验', function () {
    $tooShort = $this->putJson('/api/admin/users/'.$this->buyer->id.'/password', [
        'password' => 'abc123',
        'password_confirmation' => 'abc123',
    ], $this->adminAuth);
    expect($tooShort->json('code'))->toBe(40000);

    $pureNum = $this->putJson('/api/admin/users/'.$this->buyer->id.'/password', [
        'password' => '12345678',
        'password_confirmation' => '12345678',
    ], $this->adminAuth);
    expect($pureNum->json('code'))->toBe(40000);

    $weak = $this->putJson('/api/admin/users/'.$this->buyer->id.'/password', [
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
    ], $this->adminAuth);
    expect($weak->json('code'))->toBe(40000);

    $mismatch = $this->putJson('/api/admin/users/'.$this->buyer->id.'/password', [
        'password' => 'NewPass@2024',
        'password_confirmation' => 'NewPass@2025',
    ], $this->adminAuth);
    expect($mismatch->json('code'))->toBe(40000);
});

// 无权限 / 用户不存在
test('TC-USER-011 重置密码权限与存在性校验', function () {
    $this->putJson('/api/admin/users/'.$this->buyer->id.'/password', [
        'password' => 'NewPass@2024',
        'password_confirmation' => 'NewPass@2024',
    ], $this->buyerAuth)->assertStatus(403);

    $this->putJson('/api/admin/users/99999999/password', [
        'password' => 'NewPass@2024',
        'password_confirmation' => 'NewPass@2024',
    ], $this->adminAuth)->assertJson(['code' => 40004]);
});
