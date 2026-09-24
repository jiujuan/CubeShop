<?php

use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * 认证相关限流（刷新验证码频繁撞 429 → 额度改为 .env 可配）
 *
 * 钉的是两件事：
 * 1. **验证码图片有独立额度**：它原先和登录注册同处一组（10 次/分钟），用户刷新几下再
 *    提交一两次就会撞 429——那不是被攻击，是正常使用。现在单独走 throttle:captcha。
 * 2. **四个额度都读 config**：改 .env 即可，不用改代码。config 在 `config:cache` 之后是
 *    纯数组取用，不查库也不查缓存，不会给每个请求增加开销。
 */
beforeEach(function () {
    Cache::flush();
});

test('TC-AUTH-RATE-01 图形验证码走独立额度，不被认证组的额度误伤', function () {
    // 验证码只给 2 次，认证组给 60 次：若验证码仍挂在组上，第三次不会是 429
    config([
        'services.auth.captcha_rate_limit' => 2,
        'services.auth.rate_limit' => 60,
    ]);

    $this->postJson('/api/auth/captcha', ['scene' => 'web'])->assertOk();
    $this->postJson('/api/auth/captcha', ['scene' => 'web'])->assertOk();
    $this->postJson('/api/auth/captcha', ['scene' => 'web'])->assertStatus(429);
});

test('TC-AUTH-RATE-02 登录限流按账号维度计数，额度由 config 决定', function () {
    config([
        'services.auth.login_rate_limit' => 1,
        'services.auth.rate_limit' => 60,
    ]);

    $cap = app(CaptchaService::class)->generate('web');

    $payload = [
        'username' => 'nobody',
        'password' => 'Wrong@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ];

    $this->postJson('/api/auth/login', $payload);
    // 同一账号第二次即被限流（认证组额度仍是 60，说明命中的是按账号的登录限流）
    $this->postJson('/api/auth/login', $payload)->assertStatus(429);
});

test('TC-AUTH-RATE-03 短信登录没有用户名时按手机号计数，不共用空 key', function () {
    config([
        'services.auth.login_rate_limit' => 1,
        'services.auth.rate_limit' => 60,
    ]);

    $payload = ['phone' => '13800138000', 'sms_code' => '000000'];

    $this->postJson('/api/auth/login', $payload);
    $this->postJson('/api/auth/login', $payload)->assertStatus(429);

    // 换个手机号是另一条额度：所有短信登录若共用一个空 key，这里也会被限流
    $other = $this->postJson('/api/auth/login', ['phone' => '13800138001', 'sms_code' => '000000']);

    expect($other->status())->not->toBe(429);
});

test('TC-AUTH-RATE-04 四项认证限流配置均已登记（漏配会静默回退默认值）', function () {
    expect(config('services.auth'))->toHaveKeys([
        'rate_limit',
        'captcha_rate_limit',
        'login_rate_limit',
        'register_rate_limit',
    ]);
});
