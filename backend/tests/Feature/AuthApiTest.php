<?php

use App\Models\User;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => true]);
    seedRoles();
});

/**
 * 注册新用户并返回 token
 */
function registerViaApi(string $username = 'u1', string $password = 'Test@1234'): array
{
    $cap = app(CaptchaService::class)->generate();

    $resp = test()->postJson('/api/auth/register', [
        'username' => $username,
        'password' => $password,
        'password_confirmation' => $password,
        'code' => $cap['debug_code'] ?? 'XXXX',
        'captcha_id' => $cap['captcha_id'],
    ]);

    return $resp->json();
}

function loginViaApi(string $username = 'u1', string $password = 'Test@1234'): array
{
    $cap = app(CaptchaService::class)->generate();

    return test()->postJson('/api/auth/login', [
        'username' => $username,
        'password' => $password,
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'] ?? 'XXXX',
    ])->json();
}

function authHeader(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

// USER-001 注册成功
test('TC-USER-001 注册成功返回 token 与用户信息', function () {
    $body = registerViaApi('newuser');

    expect($body['code'])->toBe(0)
        ->and($body['data']['token'])->not->toBeEmpty()
        ->and($body['data']['user']['username'])->toBe('newuser');

    expect(User::where('username', 'newuser')->exists())->toBeTrue();
});

// USER-002 重复注册
test('TC-USER-002 重复用户名注册被拒绝', function () {
    registerViaApi('dupuser');
    $body = registerViaApi('dupuser');

    expect($body['code'])->not->toBe(0);
});

// USER-003 注册验证码错误
test('TC-USER-003 注册验证码错误被拒绝', function () {
    $cap = app(CaptchaService::class)->generate();

    $resp = $this->postJson('/api/auth/register', [
        'username' => 'capfail',
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => 'WRONG',
        'captcha_id' => $cap['captcha_id'],
    ]);

    expect($resp->json('code'))->not->toBe(0);
});

// USER-003b 密码强度校验（SEC-05）：文案必须是中文，否则前端只显示「参数校验失败」，用户会误判成验证码错了
test('TC-USER-003b 注册密码不合规则时返回中文字段提示', function () {
    $cap = app(CaptchaService::class)->generate();

    // 纯字母：缺数字
    $resp = $this->postJson('/api/auth/register', [
        'username' => 'weakuser1',
        'password' => 'abcdefgh',
        'password_confirmation' => 'abcdefgh',
        'code' => $cap['debug_code'] ?? 'XXXX',
        'captcha_id' => $cap['captcha_id'],
    ]);
    $resp->assertStatus(422);

    $messages = collect($resp->json('data.errors.password'))->implode(' ');
    expect($messages)->toContain('密码需同时包含字母和数字')
        ->and($messages)->not->toContain('must contain at least one number');

    // 纯数字：缺字母
    $cap2 = app(CaptchaService::class)->generate();
    $resp2 = $this->postJson('/api/auth/register', [
        'username' => 'weakuser2',
        'password' => '12345678',
        'password_confirmation' => '12345678',
        'code' => $cap2['debug_code'] ?? 'XXXX',
        'captcha_id' => $cap2['captcha_id'],
    ]);
    $resp2->assertStatus(422);
    expect(collect($resp2->json('data.errors.password'))->implode(' '))->toContain('密码需同时包含字母和数字');
});

// USER-004 登录成功
test('TC-USER-004 登录成功返回 token', function () {
    registerViaApi('loginuser');
    $body = loginViaApi('loginuser');

    expect($body['code'])->toBe(0)
        ->and($body['data']['token'])->not->toBeEmpty();
});

// USER-005 密码错误
test('TC-USER-005 密码错误登录被拒绝', function () {
    registerViaApi('pwduser');
    $body = loginViaApi('pwduser', 'Wrong@999');

    expect($body['code'])->not->toBe(0);
});

// USER-006 密码重置
test('TC-USER-006 重置密码后可用新密码登录', function () {
    registerViaApi('resetuser');

    $cap = app(CaptchaService::class)->generate();
    $resp = $this->postJson('/api/auth/reset-password', [
        'target' => 'resetuser',
        'password' => 'NewPass@123',
        'password_confirmation' => 'NewPass@123',
        'captcha_id' => $cap['captcha_id'],
        'code' => $cap['debug_code'] ?? 'XXXX',
    ]);

    expect($resp->json('code'))->toBe(0);

    $body = loginViaApi('resetuser', 'NewPass@123');
    expect($body['code'])->toBe(0);
});

// USER-007 新增收货地址
test('TC-USER-007 新增收货地址成功', function () {
    $token = registerViaApi('addruser')['data']['token'];

    $resp = $this->postJson('/api/user/addresses', [
        'contact_name' => '张三',
        'contact_phone' => '13800001111',
        'province' => '广东省',
        'city' => '深圳市',
        'district' => '南山区',
        'detail_address' => '科技园路 1 号',
        'is_default' => true,
    ], authHeader($token));

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.contact_name'))->toBe('张三');
});

// USER-008 编辑与删除地址
test('TC-USER-008 编辑与删除收货地址', function () {
    $token = registerViaApi('addruser2')['data']['token'];
    $id = $this->postJson('/api/user/addresses', [
        'contact_name' => '李四', 'contact_phone' => '13900002222',
        'province' => '广东省', 'city' => '深圳市', 'district' => '福田区', 'detail_address' => '路 2 号',
    ], authHeader($token))->json('data.id');

    $upd = $this->putJson("/api/user/addresses/{$id}", [
        'contact_name' => '李四改', 'contact_phone' => '13900002222',
        'province' => '广东省', 'city' => '深圳市', 'district' => '福田区', 'detail_address' => '路 2 号',
    ], authHeader($token));
    expect($upd->json('data.contact_name'))->toBe('李四改');

    $del = $this->deleteJson("/api/user/addresses/{$id}", [], authHeader($token));
    expect($del->json('code'))->toBe(0);
});

// 认证拦截：未登录访问受保护接口 401（API 约定）
test('未登录访问受保护接口返回 401', function () {
    $this->getJson('/api/user/profile')->assertStatus(401);
    $this->getJson('/api/orders')->assertStatus(401);
    $this->getJson('/api/cart')->assertStatus(401);
});

// 无效 token 401
test('无效 token 返回 401', function () {
    $this->getJson('/api/user/profile', authHeader('invalid-token'))->assertStatus(401);
});

// me 接口
test('GET /auth/me 返回当前用户', function () {
    $token = registerViaApi('meuser')['data']['token'];

    $resp = $this->getJson('/api/auth/me', authHeader($token));

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.username'))->toBe('meuser');
});
