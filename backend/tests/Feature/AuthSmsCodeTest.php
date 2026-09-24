<?php

use App\Models\SmsConfig;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Sms\SmsAutoEnable;
use App\Services\Sms\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/*
 * 短信验证码接入注册 / 登录 / 重置密码（短信渠道计划 第一期 · AuthController 改造）
 *
 * 钉的是四条需求背后的行为，而不只是「接口能调通」：
 * 1. **短信优先**：就绪时前端拿到 mode=sms（6 位），否则 captcha（5 位）——位数不同，
 *    前端输入框与校验都跟着 mode 走；
 * 2. **图形验证码不去掉**：发送短信验证码前必须先过图形码，这是短信轰炸的第一道闸；
 * 3. **回退不打断**：系统级不就绪时返回 mode=captcha 而不是报错，用户仍能走完注册/登录；
 * 4. **不泄露账号是否存在**：未注册手机号走短信登录，返回中性文案（SEC-08）。
 *
 * 另有一组覆盖「配好服务商账号就自动启用」的三条边界：Mock 不触发、没模板的场景不启用、
 * 管理员手动设置过之后不再自动改写。
 */
beforeEach(function () {
    seedRoles();
    Cache::flush();
});

/** 让短信就绪：开总开关 + 勾场景 + 配模板 + 启用渠道（测试环境允许 Mock 走真实链路） */
function smsMakeReady(array $scenes = ['register', 'login', 'reset_password']): void
{
    SmsConfig::query()->updateOrCreate(
        ['provider' => 'mock'],
        ['name' => 'Mock 渠道', 'is_enabled' => true, 'sign_name' => 'CubeShop'],
    );

    app(SmsSettings::class)->updateSwitches([
        'sms.enabled' => true,
        'sms.code_scenes' => $scenes,
        'sms.code_templates' => [
            'register' => 'SMS_REG',
            'login' => 'SMS_LOGIN',
            'reset_password' => 'SMS_RESET',
        ],
    ]);
}

/** 取一张图形验证码并请求发送短信验证码（防刷闸门就在这一步） */
function smsRequestCode(string $scene, string $phone, ?string $captchaCode = null): \Illuminate\Testing\TestResponse
{
    $cap = app(CaptchaService::class)->generate('web');

    return test()->postJson('/api/auth/send-sms-code', [
        'scene' => $scene,
        'phone' => $phone,
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $captchaCode ?? $cap['debug_code'],
    ]);
}

/** 读缓存里的验证码（键格式由 SmsCodeService 决定，这里一并钉住） */
function smsCachedCode(string $scene, string $phone): ?string
{
    return Cache::get(sprintf('sms_code:%s:%s', $scene, $phone));
}

/** 造一个带手机号的买家 */
function smsMakeUser(string $username, string $phone): User
{
    return User::create([
        'username' => $username,
        'password' => Hash::make('Oldpass123'),
        'phone' => $phone,
        'nickname' => $username,
        'status' => 1,
    ]);
}

// ---------------- verify-mode ----------------

test('TC-AUTH-SMS-001 未启用短信时 verify-mode 返回 captcha 与 5 位', function () {
    $this->getJson('/api/auth/verify-mode?scene=register')
        ->assertOk()
        ->assertJsonPath('data.mode', 'captcha')
        ->assertJsonPath('data.code_length', 5);
});

test('TC-AUTH-SMS-002 短信就绪时 verify-mode 返回 sms 与 6 位', function () {
    smsMakeReady();

    $this->getJson('/api/auth/verify-mode?scene=login')
        ->assertOk()
        ->assertJsonPath('data.mode', 'sms')
        ->assertJsonPath('data.code_length', 6)
        ->assertJsonPath('data.reason', null);
});

test('TC-AUTH-SMS-003 场景未勾选时该场景仍走图形验证码', function () {
    smsMakeReady(['register']); // 只开注册

    $this->getJson('/api/auth/verify-mode?scene=login')
        ->assertJsonPath('data.mode', 'captcha')
        ->assertJsonPath('data.reason', 'scene_not_enabled');

    $this->getJson('/api/auth/verify-mode?scene=register')
        ->assertJsonPath('data.mode', 'sms');
});

test('TC-AUTH-SMS-004 未知场景回退到 register 而不是报错', function () {
    $this->getJson('/api/auth/verify-mode?scene=not_a_scene')
        ->assertOk()
        ->assertJsonPath('data.scene', 'register');
});

// ---------------- send-sms-code ----------------

test('TC-AUTH-SMS-005 发送验证码必须先过图形验证码（防刷）', function () {
    smsMakeReady();

    smsRequestCode('register', '13800138000', 'WRONG')
        ->assertStatus(400);

    // 图形码不过就不该产生任何验证码
    expect(smsCachedCode('register', '13800138000'))->toBeNull();
});

test('TC-AUTH-SMS-006 系统未就绪时返回 captcha 回退而不报错', function () {
    // 只配渠道，不开总开关
    SmsConfig::query()->updateOrCreate(['provider' => 'mock'], ['is_enabled' => true]);

    smsRequestCode('register', '13800138000')
        ->assertOk()
        ->assertJsonPath('data.sent', false)
        ->assertJsonPath('data.mode', 'captcha')
        ->assertJsonPath('data.reason', 'sms_disabled');
});

test('TC-AUTH-SMS-007 短信就绪时发送成功并落验证码与日志', function () {
    smsMakeReady();

    smsRequestCode('register', '13800138000')
        ->assertOk()
        ->assertJsonPath('data.sent', true)
        ->assertJsonPath('data.mode', 'sms')
        ->assertJsonPath('data.code_length', 6);

    $code = smsCachedCode('register', '13800138000');

    expect($code)->toBeString()->and(strlen($code))->toBe(6);
    expect(SmsLog::query()->where('scene', 'register')->where('status', 'sent')->exists())->toBeTrue();
});

test('TC-AUTH-SMS-008 非法手机号被拒（422）', function () {
    smsMakeReady();

    smsRequestCode('register', '12345')->assertStatus(422);
});

test('TC-AUTH-SMS-009 60 秒内重复发送被拒且给出明确文案', function () {
    smsMakeReady();

    smsRequestCode('login', '13800138000')->assertOk();

    $resp = smsRequestCode('login', '13800138000');

    $resp->assertStatus(400);
    expect(json_encode($resp->json(), JSON_UNESCAPED_UNICODE))->toContain('发送过于频繁');
});

test('TC-AUTH-SMS-010 未注册手机号也能发码（不泄露是否注册）', function () {
    smsMakeReady();

    smsRequestCode('login', '13900139000')
        ->assertOk()
        ->assertJsonPath('data.sent', true);
});

// ---------------- 注册 ----------------

test('TC-AUTH-SMS-011 注册：短信验证码模式成功', function () {
    smsMakeReady();

    smsRequestCode('register', '13800138000')->assertOk();
    $code = smsCachedCode('register', '13800138000');

    $this->postJson('/api/auth/register', [
        'username' => 'smsuser',
        'password' => 'Abcd1234',
        'password_confirmation' => 'Abcd1234',
        'phone' => '13800138000',
        'sms_code' => $code,
    ])->assertOk()->assertJsonPath('data.user.username', 'smsuser');

    expect(User::where('username', 'smsuser')->exists())->toBeTrue();
});

test('TC-AUTH-SMS-012 注册：短信验证码错误被拒', function () {
    smsMakeReady();

    smsRequestCode('register', '13800138000')->assertOk();

    $this->postJson('/api/auth/register', [
        'username' => 'smsuser2',
        'password' => 'Abcd1234',
        'password_confirmation' => 'Abcd1234',
        'phone' => '13800138000',
        'sms_code' => '000000',
    ])->assertStatus(400);

    expect(User::where('username', 'smsuser2')->exists())->toBeFalse();
});

test('TC-AUTH-SMS-013 注册：短信模式未就绪时给出明确文案而非「验证码错误」', function () {
    // 场景勾了但总开关没开
    app(SmsSettings::class)->updateSwitches([
        'sms.code_scenes' => ['register'],
        'sms.code_templates' => ['register' => 'SMS_REG'],
    ]);

    $this->postJson('/api/auth/register', [
        'username' => 'smsuser3',
        'password' => 'Abcd1234',
        'password_confirmation' => 'Abcd1234',
        'phone' => '13800138000',
        'sms_code' => '123456',
    ])->assertStatus(400);

    expect(User::where('username', 'smsuser3')->exists())->toBeFalse();
});

test('TC-AUTH-SMS-014 注册：不带 sms_code 时仍走图形验证码（回归）', function () {
    $cap = app(CaptchaService::class)->generate('web');

    $this->postJson('/api/auth/register', [
        'username' => 'capuser',
        'password' => 'Abcd1234',
        'password_confirmation' => 'Abcd1234',
        'captcha_id' => $cap['captcha_id'],
        'code' => $cap['debug_code'],
    ])->assertOk()->assertJsonPath('data.user.username', 'capuser');
});

// ---------------- 登录 ----------------

test('TC-AUTH-SMS-015 登录：短信验证码模式成功', function () {
    smsMakeReady();
    smsMakeUser('smslogin', '13800138000');

    smsRequestCode('login', '13800138000')->assertOk();
    $code = smsCachedCode('login', '13800138000');

    $this->postJson('/api/auth/login', [
        'phone' => '13800138000',
        'sms_code' => $code,
    ])->assertOk()->assertJsonPath('data.user.username', 'smslogin');
});

test('TC-AUTH-SMS-016 登录：未注册手机号返回中性文案不泄露存在性', function () {
    smsMakeReady(['login']);

    smsRequestCode('login', '13900139000')->assertOk();
    $code = smsCachedCode('login', '13900139000');

    $resp = $this->postJson('/api/auth/login', [
        'phone' => '13900139000',
        'sms_code' => $code,
    ]);

    $resp->assertStatus(400);
    expect(json_encode($resp->json(), JSON_UNESCAPED_UNICODE))->toContain('验证码错误或账号信息不可用');
});

test('TC-AUTH-SMS-017 登录：密码模式在短信就绪时依然可用', function () {
    smsMakeReady();
    smsMakeUser('pwduser', '13800138000');

    $cap = app(CaptchaService::class)->generate('web');

    $this->postJson('/api/auth/login', [
        'username' => 'pwduser',
        'password' => 'Oldpass123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->assertOk()->assertJsonPath('data.user.username', 'pwduser');
});

test('TC-AUTH-SMS-018 登录：被锁账号即使短信验证码正确也拒绝（SEC-07）', function () {
    smsMakeReady();
    smsMakeUser('lockeduser', '13800138000');

    smsRequestCode('login', '13800138000')->assertOk();
    $code = smsCachedCode('login', '13800138000');

    // 连续失败达阈值即锁定（阈值见 LoginSecurityService::MAX_ATTEMPTS）
    $security = app(\App\Services\Auth\LoginSecurityService::class);

    for ($i = 0; $i < \App\Services\Auth\LoginSecurityService::MAX_ATTEMPTS; $i++) {
        $security->recordFailure('lockeduser');
    }

    expect($security->lockedSeconds('lockeduser'))->toBeGreaterThan(0);

    $this->postJson('/api/auth/login', [
        'phone' => '13800138000',
        'sms_code' => $code,
    ])->assertStatus(429);
});

// ---------------- 重置密码 ----------------

test('TC-AUTH-SMS-019 重置密码：短信验证码模式成功并吊销令牌', function () {
    smsMakeReady();
    $user = smsMakeUser('resetuser', '13800138000');

    smsRequestCode('reset_password', '13800138000')->assertOk();
    $code = smsCachedCode('reset_password', '13800138000');

    $this->postJson('/api/auth/reset-password', [
        'target' => '13800138000',
        'sms_code' => $code,
        'password' => 'Newpass123',
        'password_confirmation' => 'Newpass123',
    ])->assertOk();

    expect(Hash::check('Newpass123', $user->fresh()->password))->toBeTrue();
});

test('TC-AUTH-SMS-020 重置密码：不带 sms_code 时仍走图形验证码（回归）', function () {
    smsMakeUser('resetuser2', '13800138001');
    $cap = app(CaptchaService::class)->generate('web');

    $this->postJson('/api/auth/reset-password', [
        'target' => 'resetuser2',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
        'password' => 'Newpass123',
        'password_confirmation' => 'Newpass123',
    ])->assertOk();
});

// ---------------- 「配好账号就自动启用」 ----------------

test('TC-AUTH-SMS-021 保存阿里云凭证后自动启用已配模板的场景并开总开关', function () {
    app(SmsSettings::class)->updateSwitches([
        'sms.code_templates' => ['register' => 'SMS_REG', 'login' => 'SMS_LOGIN'],
    ]);

    $aliyun = SmsConfig::query()->where('provider', 'aliyun')->firstOrFail();

    $cap = app(CaptchaService::class)->generate();
    $auth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $this->withHeaders($auth)
        ->putJson('/api/admin/sms/config/'.$aliyun->id, [
            'access_key_id' => 'LTAI_test',
            'access_key_secret' => 'secret123',
            'sign_name' => 'CubeShop',
            'is_enabled' => true,
        ])->assertOk();

    $settings = app(SmsSettings::class);

    expect($settings->enabled())->toBeTrue();
    // reset_password 没配模板，不该被启用
    expect($settings->codeScenes())->toBe(['register', 'login']);
    expect($settings->scenesAuto())->toBeTrue();
});

test('TC-AUTH-SMS-022 Mock 渠道不触发自动启用', function () {
    app(SmsSettings::class)->updateSwitches([
        'sms.code_templates' => ['register' => 'SMS_REG'],
    ]);

    app(SmsAutoEnable::class)->sync();

    expect(app(SmsSettings::class)->enabled())->toBeFalse();
    expect(app(SmsSettings::class)->codeScenes())->toBe([]);
});

test('TC-AUTH-SMS-023 管理员手动设置过场景后不再被自动改写', function () {
    $settings = app(SmsSettings::class);

    $settings->updateSwitches(['sms.code_templates' => ['register' => 'SMS_REG', 'login' => 'SMS_LOGIN']]);
    $settings->updateSwitches(['sms.code_scenes' => ['register']]); // 人工只开注册

    expect($settings->scenesAuto())->toBeFalse();

    SmsConfig::query()->where('provider', 'mock')->update(['is_enabled' => false]);
    SmsConfig::query()->updateOrCreate(['provider' => 'aliyun'], [
        'access_key_id' => 'LTAI_test',
        'sign_name' => 'CubeShop',
        'is_enabled' => true,
    ]);
    SmsConfig::query()->where('provider', 'aliyun')->first()->update(['access_key_secret' => 'secret123']);

    app(SmsAutoEnable::class)->sync();

    expect($settings->codeScenes())->toBe(['register']);
});

test('TC-AUTH-SMS-024 注册：免密模式只提交手机号与验证码即可（手机号即账号）', function () {
    smsMakeReady(['register']);

    smsRequestCode('register', '13800138000');
    $code = smsCachedCode('register', '13800138000');

    $res = $this->postJson('/api/auth/register', [
        'phone' => '13800138000',
        'sms_code' => $code,
    ]);

    $res->assertOk();

    $user = User::where('phone', '13800138000')->first();

    expect($user)->not->toBeNull();
    // 未填用户名 → 以手机号作登录名
    expect($user->username)->toBe('13800138000');
    // 未填密码 → 服务端生成强密码，绝不是空值或可猜的弱口令
    expect($user->password)->not->toBeEmpty();
    expect(Hash::check('13800138000', $user->password))->toBeFalse();
    // 登录名即手机号，昵称要脱敏，不能在前台直接露出完整号码
    expect($user->nickname)->not->toBe('13800138000');
    expect($user->nickname)->toContain('8000');
});

test('TC-AUTH-SMS-025 注册：免密账号可凭「手机号 + 短信验证码」登录（闭环）', function () {
    smsMakeReady(['register', 'login']);

    smsRequestCode('register', '13800138001');
    $this->postJson('/api/auth/register', [
        'phone' => '13800138001',
        'sms_code' => smsCachedCode('register', '13800138001'),
    ])->assertOk();

    // 60 秒重发间隔按手机号计数（防刷规则，非本次要验的行为），清掉后继续走登录
    Cache::forget('sms_code_last:13800138001');

    smsRequestCode('login', '13800138001');
    $res = $this->postJson('/api/auth/login', [
        'phone' => '13800138001',
        'sms_code' => smsCachedCode('login', '13800138001'),
    ]);

    $res->assertOk();
    expect($res->json('data.token'))->not->toBeEmpty();
});

test('TC-AUTH-SMS-026 注册：手机号已被他人用作用户名时拒绝且不区分原因', function () {
    smsMakeReady(['register']);

    // 另一个账号把 13800138002 用作用户名（手机号是别的号）
    User::create([
        'username' => '13800138002',
        'password' => Hash::make('Oldpass123'),
        'phone' => '13900139000',
        'nickname' => '占用者',
        'status' => 1,
    ]);

    smsRequestCode('register', '13800138002');
    $res = $this->postJson('/api/auth/register', [
        'phone' => '13800138002',
        'sms_code' => smsCachedCode('register', '13800138002'),
    ]);

    // 文案与「账号已占用」压平后一致，不透露到底是手机号还是用户名撞库（SEC-08）
    $res->assertStatus(400);
    expect($res->json('message'))->toBe('该账号信息不可用，请更换后重试');
    expect(User::where('phone', '13800138002')->exists())->toBeFalse();
});

test('TC-AUTH-SMS-027 注册：密码模式仍强制要求密码（免密只存在于短信分支）', function () {
    $cap = app(CaptchaService::class)->generate('web');

    $res = $this->postJson('/api/auth/register', [
        'username' => 'nobody1',
        'captcha_id' => $cap['captcha_id'],
        'code' => $cap['debug_code'],
    ]);

    $res->assertStatus(422);
    expect($res->json('data.errors'))->toHaveKey('password');
});

test('TC-AUTH-SMS-028 注册：短信分支自设密码仍受强度规则约束（SEC-05 不放宽）', function () {
    smsMakeReady(['register']);

    smsRequestCode('register', '13800138003');

    // 弱密码
    $weak = $this->postJson('/api/auth/register', [
        'phone' => '13800138003',
        'sms_code' => smsCachedCode('register', '13800138003'),
        'password' => '123',
        'password_confirmation' => '123',
    ]);

    $weak->assertStatus(422);
    expect($weak->json('data.errors'))->toHaveKey('password');

    // 填了密码却没填确认密码
    $mismatch = $this->postJson('/api/auth/register', [
        'phone' => '13800138003',
        'sms_code' => smsCachedCode('register', '13800138003'),
        'password' => 'Goodpass123',
    ]);

    $mismatch->assertStatus(422);
    expect($mismatch->json('data.errors'))->toHaveKey('password_confirmation');
    expect(User::where('phone', '13800138003')->exists())->toBeFalse();
});
