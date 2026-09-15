<?php

use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * V1.1 T-022：管理员账号与角色管理接口
 *
 * 覆盖：账号 CRUD、自我禁用/最后超管保护、禁用即时生效（Token 失效）、
 *       重置密码、角色 CRUD 与引用保护、权限分组、操作日志筛选、权限与鉴权。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $capOp = app(CaptchaService::class)->generate();
    $this->operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator',
        'password' => 'Operator@123',
        'captcha_id' => $capOp['captcha_id'],
        'captcha_code' => $capOp['debug_code'],
    ])->json('data.token')];

    $this->adminId = (int) SysUser::where('username', 'admin')->value('id');
    $this->operatorId = (int) SysUser::where('username', 'operator')->value('id');
});

/** 走验证码登录，返回 token（失败返回 null） */
function loginToken(string $username, string $password): ?string
{
    $cap = app(CaptchaService::class)->generate();

    $resp = test()->postJson('/api/auth/login', [
        'username' => $username,
        'password' => $password,
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ]);

    return $resp->json('data.token');
}

// ---------- 账号列表 ----------

test('TC-ACC-001 账号列表仅含后台角色并支持筛选', function () {
    $resp = $this->getJson('/api/admin/accounts', $this->adminAuth)->json('data');

    $names = array_column($resp['list'], 'username');
    expect($names)->toContain('admin')->toContain('operator')
        ->and($resp['pagination']['total'])->toBe(2);

    // 角色筛选
    $onlyOp = $this->getJson('/api/admin/accounts?role=operator', $this->adminAuth)->json('data');
    expect(array_column($onlyOp['list'], 'username'))->toBe(['operator']);

    // 关键词
    $kw = $this->getJson('/api/admin/accounts?keyword=admin', $this->adminAuth)->json('data');
    expect(array_column($kw['list'], 'username'))->toContain('admin');
});

test('TC-ACC-002 买家账号不出现在管理员列表', function () {
    $buyer = createTestUser('buyer');
    $buyer->assignRole('customer');

    $resp = $this->getJson('/api/admin/accounts', $this->adminAuth)->json('data');

    expect(array_column($resp['list'], 'username'))->not->toContain($buyer->username);
});

// ---------- 新增 ----------

test('TC-ACC-003 新增管理员账号并分配角色', function () {
    $resp = $this->postJson('/api/admin/accounts', [
        'username' => 'cs_agent',
        'password' => 'Cs@123456',
        'nickname' => '客服小王',
        'email' => 'cs@example.com',
        'roles' => ['operator'],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0);

    $user = SysUser::where('username', 'cs_agent')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('operator'))->toBeTrue()
        ->and($user->status)->toBe(1);
});

test('TC-ACC-004 用户名重复返回 422', function () {
    $this->postJson('/api/admin/accounts', [
        'username' => 'operator',
        'password' => 'Abcd1234',
        'roles' => ['operator'],
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-ACC-005 弱密码（纯字母/过短）返回 422', function () {
    $this->postJson('/api/admin/accounts', [
        'username' => 'weak1', 'password' => 'abcdefgh', 'roles' => ['operator'],
    ], $this->adminAuth)->assertStatus(422);

    $this->postJson('/api/admin/accounts', [
        'username' => 'weak2', 'password' => 'a1', 'roles' => ['operator'],
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-ACC-006 非法角色返回 422', function () {
    $this->postJson('/api/admin/accounts', [
        'username' => 'badrole', 'password' => 'Abcd1234', 'roles' => ['customer'],
    ], $this->adminAuth)->assertStatus(422);
});

// ---------- 编辑 ----------

test('TC-ACC-007 编辑账号资料与角色', function () {
    $resp = $this->putJson("/api/admin/accounts/{$this->operatorId}", [
        'nickname' => '运营小李',
        'roles' => ['operator'],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0);
    expect(SysUser::find($this->operatorId)->nickname)->toBe('运营小李');
});

test('TC-ACC-008 不能将最后一个超管降级', function () {
    // admin 是唯一启用超管，尝试移除其超管角色
    $resp = $this->putJson("/api/admin/accounts/{$this->adminId}", [
        'roles' => ['operator'],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009);
    expect(SysUser::find($this->adminId)->hasRole('super_admin'))->toBeTrue();
});

test('TC-ACC-009 存在第二个超管时允许降级第一个', function () {
    $second = SysUser::create([
        'username' => 'admin2',
        'password' => Hash::make('Admin@1234'),
        'nickname' => '二号超管',
        'status' => 1,
    ]);
    $second->assignRole('super_admin');

    $resp = $this->putJson("/api/admin/accounts/{$this->adminId}", [
        'roles' => ['operator'],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0);
    expect(SysUser::find($this->adminId)->hasRole('super_admin'))->toBeFalse();
});

// ---------- 状态 ----------

test('TC-ACC-010 不能禁用当前登录账号', function () {
    $resp = $this->postJson("/api/admin/accounts/{$this->adminId}/status", ['status' => 0], $this->adminAuth);

    expect($resp->json('code'))->toBe(40000);
    expect((int) SysUser::find($this->adminId)->status)->toBe(1);
});

test('TC-ACC-011 禁用账号后其 Token 立即失效', function () {
    $token = loginToken('operator', 'Operator@123');
    expect($token)->not->toBeNull();
    $operatorAuth = ['Authorization' => 'Bearer '.$token];

    // 禁用前可访问
    $this->getJson('/api/auth/me', $operatorAuth)->assertStatus(200);

    // 超管禁用该账号
    $this->postJson("/api/admin/accounts/{$this->operatorId}/status", ['status' => 0], $this->adminAuth)
        ->assertStatus(200);

    // 原 Token 立即失效
    $this->getJson('/api/auth/me', $operatorAuth)->assertStatus(401);

    // 且无法再登录
    expect(loginToken('operator', 'Operator@123'))->toBeNull();
});

test('TC-ACC-012 启用后可重新登录', function () {
    $this->postJson("/api/admin/accounts/{$this->operatorId}/status", ['status' => 0], $this->adminAuth)->assertStatus(200);
    $this->postJson("/api/admin/accounts/{$this->operatorId}/status", ['status' => 1], $this->adminAuth)->assertStatus(200);

    expect(loginToken('operator', 'Operator@123'))->not->toBeNull();
});

// ---------- 重置密码 ----------

test('TC-ACC-013 重置密码后旧密码失效且需重新登录', function () {
    $old = loginToken('operator', 'Operator@123');
    expect($old)->not->toBeNull();

    $this->postJson("/api/admin/accounts/{$this->operatorId}/reset-password", [
        'password' => 'NewPass123',
    ], $this->adminAuth)->assertStatus(200);

    // 旧 Token 失效
    $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$old])->assertStatus(401);

    // 旧密码不可登录，新密码可登录
    expect(loginToken('operator', 'Operator@123'))->toBeNull();
    expect(loginToken('operator', 'NewPass123'))->not->toBeNull();
});

test('TC-ACC-014 重置密码也校验强度', function () {
    $this->postJson("/api/admin/accounts/{$this->operatorId}/reset-password", [
        'password' => '12345678',
    ], $this->adminAuth)->assertStatus(422);
});

// ---------- 权限 ----------

test('TC-ACC-015 无 account.manage 权限访问被拒', function () {
    // operator 没有 account.manage
    $this->getJson('/api/admin/accounts', $this->operatorAuth)->assertStatus(403);
    $this->postJson('/api/admin/accounts', ['username' => 'x1', 'password' => 'Abcd1234', 'roles' => ['operator']], $this->operatorAuth)
        ->assertStatus(403);
});

test('TC-ACC-016 未登录访问账号接口返回 401', function () {
    $this->getJson('/api/admin/accounts')->assertStatus(401);
});

// ---------- 角色 ----------

test('TC-ROL-001 角色列表含内置标记与权限分组', function () {
    $data = $this->getJson('/api/admin/roles', $this->adminAuth)->json('data');

    $byName = collect($data['roles'])->keyBy('name');
    expect($byName['super_admin']['builtin'])->toBeTrue()
        ->and($byName['super_admin']['label'])->toBe('超级管理员')
        ->and($byName['operator']['user_count'])->toBe(1);

    expect($data['permission_groups'])->not->toBeEmpty();
    $modules = array_column($data['permission_groups'], 'module');
    expect($modules)->toContain('product')->toContain('order')->toContain('report');
});

test('TC-ROL-002 新建角色并分配权限', function () {
    $resp = $this->postJson('/api/admin/roles', [
        'name' => 'cs_agent',
        'permissions' => ['order.view', 'review.manage'],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0);

    $role = \Spatie\Permission\Models\Role::where('name', 'cs_agent')->first();
    expect($role)->not->toBeNull()
        ->and($role->permissions->pluck('name')->all())->toEqualCanonicalizing(['order.view', 'review.manage']);
});

test('TC-ROL-003 编辑角色权限', function () {
    $role = \Spatie\Permission\Models\Role::create(['name' => 'temp_role', 'guard_name' => 'web']);
    $role->syncPermissions(['order.view']);

    $this->putJson("/api/admin/roles/{$role->id}", [
        'permissions' => ['order.view', 'refund.view'],
    ], $this->adminAuth)->assertStatus(200);

    expect($role->fresh()->permissions->pluck('name')->all())->toEqualCanonicalizing(['order.view', 'refund.view']);
});

test('TC-ROL-004 内置角色不可删除/重命名', function () {
    $operatorRole = \Spatie\Permission\Models\Role::where('name', 'operator')->first();

    expect($this->deleteJson("/api/admin/roles/{$operatorRole->id}", [], $this->adminAuth)->json('code'))->toBe(40003);
    expect($this->putJson("/api/admin/roles/{$operatorRole->id}", ['name' => 'op2'], $this->adminAuth)->json('code'))->toBe(40003);
});

test('TC-ROL-005 被账号引用的角色不可删除', function () {
    $role = \Spatie\Permission\Models\Role::create(['name' => 'in_use', 'guard_name' => 'web']);
    $user = createTestUser('inuse');
    $user->assignRole('in_use');

    $resp = $this->deleteJson("/api/admin/roles/{$role->id}", [], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009);
    expect(\Spatie\Permission\Models\Role::find($role->id))->not->toBeNull();
});

test('TC-ROL-006 删除无引用的自定义角色', function () {
    $role = \Spatie\Permission\Models\Role::create(['name' => 'empty_role', 'guard_name' => 'web']);

    $this->deleteJson("/api/admin/roles/{$role->id}", [], $this->adminAuth)->assertStatus(200);
    expect(\Spatie\Permission\Models\Role::find($role->id))->toBeNull();
});

test('TC-ROL-007 角色权限变更即时生效', function () {
    // 给已有角色增权：operator 默认无 user.manage
    $role = \Spatie\Permission\Models\Role::where('name', 'operator')->first();
    $this->putJson("/api/admin/roles/{$role->id}", [
        'permissions' => array_merge($role->permissions->pluck('name')->all(), ['user.manage']),
    ], $this->adminAuth)->assertStatus(200);

    // operator 现在可访问用户管理
    $this->getJson('/api/admin/users', $this->operatorAuth)->assertStatus(200);
});

test('TC-ROL-008 无 role.manage 权限访问被拒', function () {
    $this->getJson('/api/admin/roles', $this->operatorAuth)->assertStatus(403);
     $this->getJson('/api/admin/permissions', $this->operatorAuth)->assertStatus(403);
});

// ---------- 操作日志筛选 ----------

test('TC-ROL-009 操作日志支持 operator_id / module / 区间筛选', function () {
    // 产生一条账号操作日志（操作人=admin）
    $this->postJson('/api/admin/accounts', [
        'username' => 'log_target', 'password' => 'Abcd1234', 'roles' => ['operator'],
    ], $this->adminAuth)->assertStatus(200);

    $byOperator = $this->getJson("/api/admin/operation-logs?operator_id={$this->adminId}&module=account", $this->adminAuth)->json('data');
    expect($byOperator['pagination']['total'])->toBeGreaterThan(0);

    // 时间区间（覆盖今天）
    $range = $this->getJson('/api/admin/operation-logs?start='.now()->subDay()->toDateString().'&end='.now()->toDateString(), $this->adminAuth)->json('data');
    expect($range['pagination']['total'])->toBeGreaterThan(0);

    // 不存在的操作人 → 0
    $none = $this->getJson('/api/admin/operation-logs?operator_id=999999', $this->adminAuth)->json('data');
    expect($none['pagination']['total'])->toBe(0);
});
