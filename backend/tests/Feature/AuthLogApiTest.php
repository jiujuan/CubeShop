<?php

use App\Models\AuthLog;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => true]);
    seedRoles();
    // 禁用传输层限流：同 IP 在单测进程内累计易触发 429，干扰认证日志断言
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** 注册并返回响应体 */
function regUser(string $username = 'u1', string $password = 'Test@1234'): array
{
    $cap = app(CaptchaService::class)->generate();

    return test()->postJson('/api/auth/register', [
        'username' => $username,
        'password' => $password,
        'password_confirmation' => $password,
        'code' => $cap['debug_code'] ?? 'XXXX',
        'captcha_id' => $cap['captcha_id'],
    ])->json();
}

/** 登录并返回响应体 */
function logUser(string $username = 'u1', string $password = 'Test@1234'): array
{
    $cap = app(CaptchaService::class)->generate();

    return test()->postJson('/api/auth/login', [
        'username' => $username,
        'password' => $password,
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'] ?? 'XXXX',
    ])->json();
}

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/** 创建一个指定角色的后台账号并登录，返回 token */
function adminLogin(string $role = 'super_admin', string $suffix = 'adm'): string
{
    $username = $suffix.uniqid();
    $user = SysUser::create([
        'username' => $username,
        'password' => \Illuminate\Support\Facades\Hash::make('Admin@1234'),
        'nickname' => 'A',
        'status' => 1,
    ]);
    $user->assignRole($role);

    $cap = app(CaptchaService::class)->generate();
    $body = test()->postJson('/api/auth/login', [
        'username' => $username,
        'password' => 'Admin@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'] ?? 'XXXX',
    ])->json();

    return $body['data']['token'];
}

// ============ 注册 ============

test('注册成功写入 auth_logs（event=register, success=true）', function () {
    $body = regUser('regok');
    expect($body['code'])->toBe(0);

    $log = AuthLog::where('event', AuthLog::EVENT_REGISTER)->where('success', true)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(AuthLog::ACTOR_CUSTOMER)
        ->and($log->identifier)->toBe('regok')
        ->and($log->user_id)->toBe(User::where('username', 'regok')->first()->id)
        ->and($log->device_id)->not->toBeNull()
        ->and($log->token_id)->not->toBeNull();
});

test('注册验证码错误写入 auth_logs（fail_reason=captcha_error）', function () {
    $username = 'regcap'.uniqid();
    $cap = app(CaptchaService::class)->generate();
    $this->postJson('/api/auth/register', [
        'username' => $username,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => 'WRONG',
        'captcha_id' => $cap['captcha_id'],
    ])->assertStatus(400);

    $log = AuthLog::where('event', AuthLog::EVENT_REGISTER)
        ->where('success', false)
        ->where('fail_reason', 'captcha_error')
        ->where('identifier', $username)
        ->first();
    expect($log)->not->toBeNull();
});

test('注册参数校验失败写入 auth_logs（fail_reason=validation，detail 含字段）', function () {
    $username = 'regweak'.uniqid();
    $cap = app(CaptchaService::class)->generate();
    $this->postJson('/api/auth/register', [
        'username' => $username,
        'password' => 'abcdefgh',
        'password_confirmation' => 'abcdefgh',
        'code' => $cap['debug_code'] ?? 'XXXX',
        'captcha_id' => $cap['captcha_id'],
    ])->assertStatus(422);

    $log = AuthLog::where('event', AuthLog::EVENT_REGISTER)
        ->where('success', false)
        ->where('fail_reason', 'validation')
        ->where('identifier', $username)
        ->first();
    expect($log)->not->toBeNull();

    $token = adminLogin('super_admin', 'supval');
    $detail = $this->getJson("/api/admin/auth-logs/{$log->id}", bearer($token))->json('data.detail');
    expect($detail)->toHaveKey('fields');
    expect($detail['fields'])->toContain('password');
});

// ============ 登录 ============

test('登录成功写入 auth_logs（event=login, success=true）', function () {
    regUser('loginok');
    AuthLog::query()->delete(); // 清掉注册日志，聚焦登录
    $body = logUser('loginok');
    expect($body['code'])->toBe(0);

    $log = AuthLog::where('event', AuthLog::EVENT_LOGIN)->where('success', true)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(AuthLog::ACTOR_CUSTOMER)
        ->and($log->identifier)->toBe('loginok')
        ->and($log->device_id)->not->toBeNull()
        ->and($log->token_id)->not->toBeNull();
});

test('登录密码错误写入 auth_logs（fail_reason=invalid_credential）', function () {
    regUser('loginpwd');
    $body = logUser('loginpwd', 'Wrong@999');
    expect($body['code'])->not->toBe(0);

    $log = AuthLog::where('event', AuthLog::EVENT_LOGIN)
        ->where('success', false)
        ->where('fail_reason', 'invalid_credential')
        ->where('identifier', 'loginpwd')
        ->first();
    expect($log)->not->toBeNull();
});

test('登录验证码错误写入 auth_logs（fail_reason=captcha_error）', function () {
    regUser('logincap');
    $cap = app(CaptchaService::class)->generate();
    $this->postJson('/api/auth/login', [
        'username' => 'logincap',
        'password' => 'Test@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => 'WRONG',
    ])->assertStatus(400);

    $log = AuthLog::where('event', AuthLog::EVENT_LOGIN)
        ->where('fail_reason', 'captcha_error')
        ->where('identifier', 'logincap')
        ->first();
    expect($log)->not->toBeNull();
});

test('登录参数校验失败写入 auth_logs（fail_reason=validation）', function () {
    regUser('loginval');
    $cap = app(CaptchaService::class)->generate();
    $this->postJson('/api/auth/login', [
        'username' => 'loginval',
        'password' => 'Test@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => substr($cap['debug_code'] ?? 'ABCDE', 0, 4),
    ])->assertStatus(422);

    $log = AuthLog::where('event', AuthLog::EVENT_LOGIN)
        ->where('fail_reason', 'validation')
        ->where('identifier', 'loginval')
        ->first();
    expect($log)->not->toBeNull();
});

test('禁用账号登录写入 auth_logs（fail_reason=account_disabled）', function () {
    $username = 'logindis'.uniqid();
    regUser($username);
    User::where('username', $username)->first()->update(['status' => 0]);

    $body = logUser($username);
    expect($body['code'])->toBe(40009); // conflict → 409 → 业务码 40009

    $log = AuthLog::where('event', AuthLog::EVENT_LOGIN)
        ->where('fail_reason', 'account_disabled')
        ->where('identifier', $username)
        ->first();
    expect($log)->not->toBeNull();
});

test('账号锁定后登录返回 429 并记录 account_locked', function () {
    $username = 'lockuser'.uniqid();
    regUser($username);

    // 连续错误密码：前 10 次累计失败，第 11 次起被锁定（429）
    $lastCode = 0;
    for ($i = 0; $i < 12; $i++) {
        $lastCode = logUser($username, 'Wrong@999')['code'];
    }
    expect($lastCode)->toBe(40029); // tooManyRequests 业务码

    expect(AuthLog::where('event', AuthLog::EVENT_LOGIN)
        ->where('fail_reason', 'account_locked')->exists())->toBeTrue();
});

// ============ 登出 ============

test('登出写入 auth_logs（event=logout, success=true）', function () {
    $token = regUser('logoutuser')['data']['token'];
    AuthLog::query()->delete(); // 清掉注册日志

    $body = $this->postJson('/api/auth/logout', [], bearer($token))->json();
    expect($body['code'])->toBe(0);

    $log = AuthLog::where('event', AuthLog::EVENT_LOGOUT)->where('success', true)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(AuthLog::ACTOR_CUSTOMER)
        ->and($log->identifier)->toBe('logoutuser')
        ->and($log->device_id)->not->toBeNull()
        ->and($log->token_id)->not->toBeNull();
});

// ============ 后台查看接口 ============

test('超管可查看认证日志列表并含映射字段', function () {
    regUser('listuser');
    logUser('listuser');

    $token = adminLogin('super_admin', 'sup');
    $resp = $this->getJson('/api/admin/auth-logs', bearer($token));
    $resp->assertOk();

    $list = $resp->json('data.list');
    expect($list)->not->toBeEmpty();
    expect($list[0])->toHaveKeys([
        'id', 'event', 'event_label', 'actor_label', 'identifier',
        'success', 'success_label', 'ip', 'user_agent', 'created_at',
    ]);
});

test('运营可查看认证日志列表', function () {
    $token = adminLogin('operator', 'opr');
    $resp = $this->getJson('/api/admin/auth-logs', bearer($token));
    $resp->assertOk();
});

test('无 log.auth.view 权限的角色访问返回 403', function () {
    $token = adminLogin('cs_agent', 'csa'); // cs_agent 不含 log.auth.view
    $resp = $this->getJson('/api/admin/auth-logs', bearer($token));
    $resp->assertStatus(403);
});

test('认证日志详情接口返回完整字段', function () {
    regUser('detailuser');
    $id = AuthLog::where('event', AuthLog::EVENT_REGISTER)->where('success', true)->first()->id;
    $token = adminLogin('super_admin', 'supd');

    $resp = $this->getJson("/api/admin/auth-logs/{$id}", bearer($token));
    $resp->assertOk();
    expect($resp->json('data.id'))->toBe($id)
        ->and($resp->json('data.detail'))->toBeNull();
});

test('认证日志列表支持按 event 与 success 筛选', function () {
    regUser('filteruser');
    logUser('filteruser', 'Bad@9999'); // 一条失败
    logUser('filteruser');             // 一条成功

    $token = adminLogin('super_admin', 'supf');

    $failList = $this->getJson('/api/admin/auth-logs?event=login&success=0', bearer($token))->json('data.list');
    expect($failList)->not->toBeEmpty();
    foreach ($failList as $row) {
        expect($row['event'])->toBe('login');
        expect($row['success'])->toBeFalse();
    }

    $okList = $this->getJson('/api/admin/auth-logs?event=login&success=1', bearer($token))->json('data.list');
    foreach ($okList as $row) {
        expect($row['event'])->toBe('login');
        expect($row['success'])->toBeTrue();
    }
});
