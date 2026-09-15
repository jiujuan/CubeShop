<?php

use App\Models\Notification;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 E05-B / T-027：改密强化
 *
 * 覆盖：旧密码校验、强度规则（可配置）、撤销其他设备 Token、通知、登录切换。
 */
beforeEach(function () {
    seedRoles();
    $this->seed(\Database\Seeders\AuthSecuritySeeder::class);
    config(['app.debug' => true]);

    $this->username = 'pwd'.uniqid();
    $this->password = 'OldPass123';

    $cap = app(CaptchaService::class)->generate();
    $this->tokenA = $this->postJson('/api/auth/register', [
        'username' => $this->username,
        'password' => $this->password,
        'password_confirmation' => $this->password,
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');

    // 第二个设备登录 → tokenB
    $cap2 = app(CaptchaService::class)->generate();
    $this->tokenB = $this->postJson('/api/auth/login', [
        'username' => $this->username,
        'password' => $this->password,
        'captcha_id' => $cap2['captcha_id'],
        'captcha_code' => $cap2['debug_code'],
    ])->json('data.token');

    $this->authA = ['Authorization' => 'Bearer '.$this->tokenA];
    $this->authB = ['Authorization' => 'Bearer '.$this->tokenB];
});

test('TC-PWD-001 改密成功：其他设备 Token 失效，当前 Token 仍可用', function () {
    $resp = $this->postJson('/api/auth/password', [
        'old_password' => $this->password,
        'password' => 'NewPass456',
        'password_confirmation' => 'NewPass456',
    ], $this->authA)->json();

    expect($resp['code'])->toBe(0)
        ->and($resp['data']['revoked_tokens'])->toBe(1);

    // 当前设备仍可用
    $this->getJson('/api/auth/me', $this->authA)->assertOk();
    // 其他设备已下线
    $this->getJson('/api/auth/me', $this->authB)->assertStatus(401);
});

test('TC-PWD-002 旧密码错误被拒绝，且不改动密码', function () {
    $resp = $this->postJson('/api/auth/password', [
        'old_password' => 'WrongPass999',
        'password' => 'NewPass456',
        'password_confirmation' => 'NewPass456',
    ], $this->authA);

    $resp->assertStatus(422);
    expect($this->getJson('/api/auth/me', $this->authB)->status())->toBe(200); // 另一设备未被影响
});

test('TC-PWD-003 弱密码（纯字母 / 纯数字 / 过短）被拒绝', function () {
    foreach (['abcdefgh', '12345678', 'Ab1'] as $weak) {
        $resp = $this->postJson('/api/auth/password', [
            'old_password' => $this->password,
            'password' => $weak,
            'password_confirmation' => $weak,
        ], $this->authA);
        $resp->assertStatus(422);
    }
});

test('TC-PWD-004 新密码与旧密码相同被拒绝', function () {
    $resp = $this->postJson('/api/auth/password', [
        'old_password' => $this->password,
        'password' => $this->password,
        'password_confirmation' => $this->password,
    ], $this->authA);
    $resp->assertStatus(422);
});

test('TC-PWD-005 未登录或缺少旧密码返回 401 / 422', function () {
    $this->postJson('/api/auth/password', [
        'password' => 'NewPass456', 'password_confirmation' => 'NewPass456',
    ])->assertStatus(401);

    $this->postJson('/api/auth/password', [
        'password' => 'NewPass456', 'password_confirmation' => 'NewPass456',
    ], $this->authA)->assertStatus(422);
});

test('TC-PWD-006 改密后新密码可登录，旧密码不可登录', function () {
    $this->postJson('/api/auth/password', [
        'old_password' => $this->password,
        'password' => 'NewPass456',
        'password_confirmation' => 'NewPass456',
    ], $this->authA);

    $cap = app(CaptchaService::class)->generate();
    $newLogin = $this->postJson('/api/auth/login', [
        'username' => $this->username, 'password' => 'NewPass456',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json();
    expect($newLogin['code'])->toBe(0);

    $cap2 = app(CaptchaService::class)->generate();
    $oldLogin = $this->postJson('/api/auth/login', [
        'username' => $this->username, 'password' => $this->password,
        'captcha_id' => $cap2['captcha_id'], 'captcha_code' => $cap2['debug_code'],
    ])->json();
    expect($oldLogin['code'])->not->toBe(0);
});

test('TC-PWD-007 改密后落库站内信通知', function () {
    $userId = $this->getJson('/api/auth/me', $this->authA)->json('data.id');

    $this->postJson('/api/auth/password', [
        'old_password' => $this->password,
        'password' => 'NewPass456',
        'password_confirmation' => 'NewPass456',
    ], $this->authA);

    $n = Notification::where('user_id', $userId)
        ->where('type', NotificationService::TYPE_PASSWORD_CHANGED)
        ->first();
    expect($n)->not->toBeNull()
        ->and($n->title)->toBe('密码已变更');
});

test('TC-PWD-008 强度规则可配置：关闭混合要求后纯字母可通过', function () {
    \App\Models\SystemConfig::updateOrCreate(
        ['config_key' => 'auth.password_require_mixed'],
        ['config_value' => '0', 'description' => '测试'],
    );
    app(\App\Services\Common\ConfigService::class)->flush();

    $resp = $this->postJson('/api/auth/password', [
        'old_password' => $this->password,
        'password' => 'abcdefghij',
        'password_confirmation' => 'abcdefghij',
    ], $this->authA)->json();

    expect($resp['code'])->toBe(0);
});
