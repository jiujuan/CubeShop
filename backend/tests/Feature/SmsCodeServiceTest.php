<?php

use App\Models\SmsConfig;
use App\Services\Sms\SmsCodeService;
use App\Services\Sms\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * 手机验证码用例（短信渠道计划 第一期）
 *
 * 钉的是四道闸门：60 秒重发间隔、24 小时 10 条、连续错 5 次锁 15 分钟、验证码一次性。
 * 这不是「顺手加的限流」，而是短信计费 + 账号撞库两件事的交汇点，少一道都会被刷。
 */

const SMS_TEST_PHONE = '13800138000';

/** 打开总开关、配好 register 模板、只启用 mock 渠道 */
function prepareCodeService(array $templates = ['register' => 'SMS_123']): SmsCodeService
{
    app(SmsSettings::class)->updateSwitches([
        'sms.enabled' => true,
        'sms.code_templates' => $templates,
    ]);

    SmsConfig::query()->update(['is_enabled' => false]);
    SmsConfig::query()->updateOrCreate(
        ['provider' => 'mock'],
        ['is_enabled' => true, 'sign_name' => 'CubeShop'],
    );

    return app(SmsCodeService::class);
}

/** 清掉重发冷却，用于测试日限额（否则第一发之后 60 秒内都发不出） */
function clearCooldown(string $phone = SMS_TEST_PHONE): void
{
    Cache::forget('sms_code_last:'.$phone);
}

beforeEach(function () {
    Cache::flush();
});

test('TC-SMS-CODE-001 发送成功并校验通过，验证码一次性销毁', function () {
    $service = prepareCodeService();

    $sent = $service->send('register', SMS_TEST_PHONE);

    expect($sent->ok)->toBeTrue();

    $code = Cache::get('sms_code:register:'.SMS_TEST_PHONE);

    expect($code)->toBeString();
    expect(strlen($code))->toBe(SmsCodeService::LENGTH);

    // 正确码：第一次通过
    expect($service->verify('register', SMS_TEST_PHONE, $code))->toBeTrue();

    // 同一个码再用一次：失败（成功即销毁）
    expect($service->verify('register', SMS_TEST_PHONE, $code))->toBeFalse();
});

test('TC-SMS-CODE-002 错误码校验失败；连续错 5 次锁定 15 分钟且作废验证码', function () {
    $service = prepareCodeService();

    $service->send('register', SMS_TEST_PHONE);
    $code = Cache::get('sms_code:register:'.SMS_TEST_PHONE);

    expect($service->verify('register', SMS_TEST_PHONE, '000000'))->toBeFalse();

    for ($i = 0; $i < SmsCodeService::MAX_ATTEMPTS - 1; $i++) {
        $service->verify('register', SMS_TEST_PHONE, '000000');
    }

    expect($service->isLocked('register', SMS_TEST_PHONE))->toBeTrue();
    expect($service->remainingAttempts('register', SMS_TEST_PHONE))->toBe(0);

    // ⚠️ 锁定后即便拿着正确码也过不去（验证码已被作废）
    expect($service->verify('register', SMS_TEST_PHONE, $code))->toBeFalse();

    // 锁定期间连发送也被拒
    expect($service->send('register', SMS_TEST_PHONE)->errorCode)->toBe('locked');
});

test('TC-SMS-CODE-003 60 秒内重复发送被拒', function () {
    $service = prepareCodeService();

    expect($service->send('register', SMS_TEST_PHONE)->ok)->toBeTrue();

    $second = $service->send('register', SMS_TEST_PHONE);

    expect($second->ok)->toBeFalse();
    expect($second->errorCode)->toBe('rate_limited');
    expect($service->sendCooldown(SMS_TEST_PHONE))->toBeGreaterThan(0);

    // 冷却结束后可再次发送
    clearCooldown();

    expect($service->sendCooldown(SMS_TEST_PHONE))->toBe(0);
    expect($service->send('register', SMS_TEST_PHONE)->ok)->toBeTrue();
});

test('TC-SMS-CODE-004 24 小时 10 条上限', function () {
    $service = prepareCodeService();

    for ($i = 0; $i < SmsCodeService::DAILY_LIMIT; $i++) {
        clearCooldown();
        expect($service->send('register', SMS_TEST_PHONE)->ok)->toBeTrue();
    }

    expect($service->dailyCount(SMS_TEST_PHONE))->toBe(SmsCodeService::DAILY_LIMIT);

    clearCooldown();
    $blocked = $service->send('register', SMS_TEST_PHONE);

    expect($blocked->ok)->toBeFalse();
    expect($blocked->errorCode)->toBe('daily_limit');
});

test('TC-SMS-CODE-005 未配置模板：不发短信也不产生验证码缓存', function () {
    $service = prepareCodeService([]);

    $result = $service->send('register', SMS_TEST_PHONE);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('template_missing');
    expect(Cache::has('sms_code:register:'.SMS_TEST_PHONE))->toBeFalse();
});

test('TC-SMS-CODE-006 总开关关闭：发送失败且验证码不入缓存', function () {
    app(SmsSettings::class)->updateSwitches(['sms.enabled' => false]);

    $result = app(SmsCodeService::class)->send('register', SMS_TEST_PHONE);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('sms_disabled');
    expect(Cache::has('sms_code:register:'.SMS_TEST_PHONE))->toBeFalse();
});

test('TC-SMS-CODE-007 发送失败时清掉验证码，且不消耗重发机会', function () {
    // 未启用任何渠道 → 发送失败
    app(SmsSettings::class)->updateSwitches([
        'sms.enabled' => true,
        'sms.code_templates' => ['register' => 'SMS_123'],
    ]);
    SmsConfig::query()->update(['is_enabled' => false]);

    $result = app(SmsCodeService::class)->send('register', SMS_TEST_PHONE);

    expect($result->ok)->toBeFalse();
    expect(Cache::has('sms_code:register:'.SMS_TEST_PHONE))->toBeFalse();
    // 失败不算一次发送，冷却不会因此产生
    expect(app(SmsCodeService::class)->sendCooldown(SMS_TEST_PHONE))->toBe(0);
});
