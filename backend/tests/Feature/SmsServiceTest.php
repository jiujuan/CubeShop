<?php

use App\Models\SmsConfig;
use App\Models\SmsLog;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * 短信发送门面（短信渠道计划 第一期）
 *
 * 钉的是「无论成败都有痕 + 手机号脱敏 + 不落模板参数」这三条。
 * 其中模板参数不落库是**安全约定**：验证码场景的模板参数就是验证码明文，
 * 落进 sms_logs 等于把验证码写进日志，任何人查日志都能直接登录别人的账号。
 */

/** 打开总开关（走 ConfigService，与后台保存同一条路径） */
function enableSms(array $extra = []): void
{
    app(SmsSettings::class)->updateSwitches(array_merge(['sms.enabled' => true], $extra));
}

/** 只启用指定渠道（迁移种子了三行，默认 mock 是启用的） */
function enableOnlyProvider(string $provider, array $credentials = []): SmsConfig
{
    SmsConfig::query()->update(['is_enabled' => false]);

    return SmsConfig::query()->updateOrCreate(
        ['provider' => $provider],
        array_merge(['is_enabled' => true, 'sign_name' => 'CubeShop'], $credentials),
    );
}

beforeEach(function () {
    Cache::flush();
});

test('TC-SMS-SVC-001 总开关关闭：不发送、落 skipped 日志、返回失败', function () {
    Http::fake();

    $result = app(SmsService::class)->send('13800138000', 'SMS_1', ['code' => '123456'], 'register');

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('sms_disabled');

    Http::assertNothingSent();

    $log = SmsLog::query()->first();

    expect($log->status)->toBe('skipped');
    expect($log->scene)->toBe('register');
    // ⚠️ 明文手机号绝不入库
    expect($log->phone_masked)->toBe('138****8000');
});

test('TC-SMS-SVC-002 发送成功：落 sent 日志，带服务商流水号与耗时', function () {
    enableSms();
    enableOnlyProvider('mock');

    $result = app(SmsService::class)->send('13800138000', 'SMS_1', ['code' => '123456'], 'test');

    expect($result->ok)->toBeTrue();

    $log = SmsLog::query()->first();

    expect($log->status)->toBe('sent');
    expect($log->provider)->toBe('mock');
    expect($log->phone_masked)->toBe('138****8000');
    expect($log->biz_id)->toStartWith('mock-');
    expect($log->scene)->toBe('test');
    expect($log->template_code)->toBe('SMS_1');
});

test('TC-SMS-SVC-003 阿里云发送成功：日志记真实渠道与 BizId', function () {
    Http::fake(['*' => Http::response(['Code' => 'OK', 'BizId' => 'biz-9527'], 200)]);

    enableSms();
    enableOnlyProvider('aliyun', ['access_key_id' => 'LTAI_x', 'access_key_secret' => 'secret']);

    $result = app(SmsService::class)->send('13800138000', 'SMS_1', ['code' => '123456'], 'register');

    expect($result->ok)->toBeTrue();

    $log = SmsLog::query()->first();

    expect($log->provider)->toBe('aliyun');
    expect($log->biz_id)->toBe('biz-9527');
    expect($log->latency_ms)->toBeInt();
});

test('TC-SMS-SVC-004 发送失败：落 failed 日志并带上错误码，且不抛异常', function () {
    Http::fake(['*' => Http::response(['Code' => 'isv.AMOUNT_NOT_ENOUGH', 'Message' => '余额不足'], 200)]);

    enableSms();
    enableOnlyProvider('aliyun', ['access_key_id' => 'LTAI_x', 'access_key_secret' => 'secret']);

    $result = app(SmsService::class)->send('13800138000', 'SMS_1', ['code' => '123456'], 'register');

    expect($result->ok)->toBeFalse();
    expect($result->errorMsg)->toContain('余额不足');

    $log = SmsLog::query()->first();

    expect($log->status)->toBe('failed');
    expect($log->error_code)->toBe('isv.AMOUNT_NOT_ENOUGH');
});

test('TC-SMS-SVC-005 未启用任何渠道：落 skipped 并返回 no_channel', function () {
    enableSms();
    SmsConfig::query()->update(['is_enabled' => false]);

    $result = app(SmsService::class)->send('13800138000', 'SMS_1', [], 'register');

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('no_channel');
    expect(SmsLog::query()->first()->status)->toBe('skipped');
});

test('TC-SMS-SVC-006 模板参数不落库（验证码明文绝不进日志）', function () {
    enableSms();
    enableOnlyProvider('mock');

    app(SmsService::class)->send('13800138000', 'SMS_1', ['code' => '654321'], 'register');

    // 表结构上就没有存放模板参数的列 —— 想存也存不进去
    expect(Schema::hasColumn('sms_logs', 'template_params'))->toBeFalse();
    expect(Schema::hasColumn('sms_logs', 'params'))->toBeFalse();

    $log = SmsLog::query()->first();

    foreach ($log->getAttributes() as $value) {
        expect((string) $value)->not->toContain('654321');
    }
});

test('TC-SMS-SVC-007 active() 区分「配置值」与「实际生效渠道」', function () {
    enableSms();

    // 配了阿里云但没凭证 → 非生产环境实际走 Mock，degraded=true
    enableOnlyProvider('aliyun', ['access_key_id' => 'LTAI_x']);

    $active = app(SmsService::class)->active();

    expect($active['degraded'])->toBeTrue();
    expect($active['configured_provider'])->toBe('aliyun');
    expect($active['provider'])->toBe('mock');

    // 凭证补全后不再降级
    enableOnlyProvider('aliyun', ['access_key_id' => 'LTAI_x', 'access_key_secret' => 'secret']);

    $active = app(SmsService::class)->active();

    expect($active['degraded'])->toBeFalse();
    expect($active['provider'])->toBe('aliyun');
});
