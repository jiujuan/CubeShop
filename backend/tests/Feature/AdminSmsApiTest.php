<?php

use App\Models\SmsConfig;
use App\Models\SmsLog;
use App\Models\SysOperationLog;
use App\Services\Common\CaptchaService;
use App\Services\Sms\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * 短信渠道后台接口（短信渠道计划 第一期）
 *
 * 权限口径同站内搜索：admin（super_admin）持有 sms.view / sms.manage，
 * operator **两个都没有**（短信凭证等同于花钱的钥匙，风险由超管承担）。
 *
 * 这一组钉的是三类最容易出事的行为：
 * 1. **Secret 只出不进**：接口绝不返回明文，也不返回密文；
 * 2. **Secret 留空 = 不修改**：全局中间件 ConvertEmptyStringsToNull 会把 '' 变成 null，
 *    若当成「清空」处理，前端一个空表单提交就能抹掉线上凭证；
 * 3. **启用唯一性**：切换渠道必须在事务里「先关旧、再开新」，否则会同时存在两个启用渠道。
 */
beforeEach(function () {
    seedRoles();
    Cache::flush();

    $login = function (string $username, string $password): array {
        $cap = app(CaptchaService::class)->generate();

        return ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token')];
    };

    $this->adminAuth = $login('admin', 'Admin@123');
    $this->operatorAuth = $login('operator', 'Operator@123');
});

test('TC-SMS-ADMIN-001 短信配置接口仅 sms 权限持有者可用', function () {
    $this->getJson('/api/admin/sms/config')->assertStatus(401);

    // operator 不持 sms.view / sms.manage：读与写都 403
    $this->withHeaders($this->operatorAuth)
        ->getJson('/api/admin/sms/config')->assertStatus(403);

    $this->withHeaders($this->operatorAuth)
        ->putJson('/api/admin/sms/config', ['enabled' => true])->assertStatus(403);

    $this->withHeaders($this->operatorAuth)
        ->postJson('/api/admin/sms/test', [
            'phone' => '13800138000', 'template_code' => 'SMS_1',
        ])->assertStatus(403);

    $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/sms/config')->assertOk();
});

test('TC-SMS-ADMIN-002 渠道列表只回掩码，绝不返回 Secret 明文或密文', function () {
    $aliyun = SmsConfig::query()->where('provider', 'aliyun')->first();
    $aliyun->access_key_secret = 'LTAI-secret-8888';
    $aliyun->save();

    $response = $this->withHeaders($this->adminAuth)->getJson('/api/admin/sms/config')->assertOk();

    $channel = collect($response->json('data.channels'))->firstWhere('provider', 'aliyun');

    expect($channel['has_secret'])->toBeTrue();
    expect($channel['secret_masked'])->toBe('****8888');

    // 整个响应体里都不允许出现明文
    expect($response->getContent())->not->toContain('LTAI-secret-8888');
});

test('TC-SMS-ADMIN-003 更新凭证：启用切换保证全局最多一个启用渠道', function () {
    $mock = SmsConfig::query()->where('provider', 'mock')->first();
    $aliyun = SmsConfig::query()->where('provider', 'aliyun')->first();

    expect($mock->is_enabled)->toBeTrue();

    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/sms/config/'.$aliyun->id, [
            'access_key_id' => 'LTAI_new',
            'access_key_secret' => 'new-secret-9999',
            'sign_name' => 'CubeShop',
            'is_enabled' => true,
        ])->assertOk();

    expect($aliyun->fresh()->is_enabled)->toBeTrue();
    expect($mock->fresh()->is_enabled)->toBeFalse();
    expect(SmsConfig::query()->where('is_enabled', true)->count())->toBe(1);

    // 写操作留痕
    expect(SysOperationLog::query()->where('module', 'sms')->count())->toBeGreaterThan(0);
});

test('TC-SMS-ADMIN-004 Secret 留空 = 不修改（不会被误清空）', function () {
    $aliyun = SmsConfig::query()->where('provider', 'aliyun')->first();
    $aliyun->access_key_secret = 'old-secret-1234';
    $aliyun->save();

    // 空串会被全局中间件转成 null，语义是「不改」
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/sms/config/'.$aliyun->id, [
            'access_key_id' => 'LTAI_changed',
            'access_key_secret' => '',
        ])->assertOk();

    $fresh = $aliyun->fresh();

    expect($fresh->access_key_id)->toBe('LTAI_changed');
    expect($fresh->access_key_secret)->toBe('old-secret-1234');

    // 不传该字段同样是不改
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/sms/config/'.$aliyun->id, ['remark' => 'x'])->assertOk();

    expect($aliyun->fresh()->access_key_secret)->toBe('old-secret-1234');
});

test('TC-SMS-ADMIN-005 未落地服务商不可配置（腾讯云二期）', function () {
    $tencent = SmsConfig::query()->where('provider', 'tencent')->first();

    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/sms/config/'.$tencent->id, ['access_key_id' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, '二期'));
});

test('TC-SMS-ADMIN-006 更新运营开关：总开关 / 验证码场景 / 模板映射', function () {
    $this->withHeaders($this->adminAuth)
        ->putJson('/api/admin/sms/config', [
            'enabled' => true,
            'code_scenes' => ['register', 'not_a_scene'],
            'code_templates' => ['register' => 'SMS_123456', 'bogus' => 'SMS_x'],
        ])->assertOk();

    $settings = app(SmsSettings::class);

    expect($settings->enabled())->toBeTrue();
    // 非法场景名被过滤，只留下白名单内的
    expect($settings->codeScenes())->toBe(['register']);
    expect($settings->templateFor('register'))->toBe('SMS_123456');
    expect($settings->templateFor('bogus'))->toBeNull();

    $switches = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/sms/config')->json('data.switches');

    expect($switches['enabled'])->toBeTrue();
    expect($switches['code_scenes'])->toBe(['register']);
    expect($switches['code_templates'])->toBe(['register' => 'SMS_123456']);
});

test('TC-SMS-ADMIN-007 测试发送：参数校验 + 走真实链路落日志', function () {
    app(SmsSettings::class)->updateSwitches(['sms.enabled' => true]);

    // 手机号非法：422
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/sms/test', ['phone' => '12345', 'template_code' => 'SMS_1'])
        ->assertStatus(422);

    // 合法：默认启用的是 mock 渠道，应成功并落一条 scene=test 的日志
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/sms/test', [
            'phone' => '13800138000',
            'template_code' => 'SMS_1',
            'params' => ['code' => '123456'],
        ])
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    $log = SmsLog::query()->where('scene', 'test')->first();

    expect($log)->not->toBeNull();
    expect($log->phone_masked)->toBe('138****8000');
    expect($log->status)->toBe('sent');

    expect(SysOperationLog::query()->where('action', 'test.send')->count())->toBe(1);
});

test('TC-SMS-ADMIN-008 总开关关闭时测试发送返回失败，但仍留痕', function () {
    app(SmsSettings::class)->updateSwitches(['sms.enabled' => false]);

    $response = $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/sms/test', [
            'phone' => '13800138000',
            'template_code' => 'SMS_1',
        ])->assertOk();

    expect($response->json('code'))->toBe(1);
    expect($response->json('data.error_code'))->toBe('sms_disabled');

    expect(SmsLog::query()->where('status', 'skipped')->count())->toBe(1);
});

test('TC-SMS-ADMIN-009 发送记录分页：支持过滤且只回脱敏手机号', function () {
    app(SmsSettings::class)->updateSwitches(['sms.enabled' => true]);

    SmsLog::query()->create([
        'provider' => 'mock', 'phone_masked' => '138****8000',
        'scene' => 'register', 'status' => 'sent', 'template_code' => 'SMS_1',
    ]);
    SmsLog::query()->create([
        'provider' => 'aliyun', 'phone_masked' => '139****8001',
        'scene' => 'test', 'status' => 'failed', 'error_code' => 'isv.AMOUNT_NOT_ENOUGH',
    ]);

    $all = $this->withHeaders($this->adminAuth)->getJson('/api/admin/sms/logs')->assertOk();

    expect($all->json('data.pagination.total'))->toBe(2);

    $filtered = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/sms/logs?provider=aliyun&status=failed')->assertOk();

    expect($filtered->json('data.pagination.total'))->toBe(1);
    expect($filtered->json('data.list.0.phone_masked'))->toBe('139****8001');
    expect($filtered->json('data.list.0.error_code'))->toBe('isv.AMOUNT_NOT_ENOUGH');

    // page_size 上限与后台其它列表同口径（100）
    expect($filtered->json('data.pagination.page_size'))->toBe(20);
});
