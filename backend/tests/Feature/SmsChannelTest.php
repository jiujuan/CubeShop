<?php

use App\Models\SmsConfig;
use App\Support\Sms\Adapters\AliyunSmsChannel;
use App\Support\Sms\Adapters\MockSmsChannel;
use App\Support\Sms\SmsChannelFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
 * 短信渠道层（短信渠道计划 第一期）
 *
 * 钉的是三件事：工厂判定矩阵、生产 fail-closed、适配器「不抛异常 + 请求形状正确」。
 *
 * 请求形状那条尤其重要：Laravel 的 `Http::post($url)` 默认会发 `[]` 并带上
 * `Content-Type: application/json`，而签名里没有 content-type —— 网关会判签名不一致。
 * 这条用例就是防止有人把 `send(..., ['body' => ''])` 改回 `post($url)`。
 */

/** 造一条渠道配置行（迁移已种子 mock/aliyun/tencent 三行，故用 updateOrCreate） */
function makeSmsConfig(string $provider, array $overrides = []): SmsConfig
{
    return SmsConfig::query()->updateOrCreate(
        ['provider' => $provider],
        array_merge([
            'name' => '测试渠道',
            'access_key_id' => 'LTAI_test',
            'sign_name' => 'CubeShop',
            'is_enabled' => true,
        ], $overrides),
    );
}

test('TC-SMS-CH-001 mock 渠道直接返回成功且不请求外部服务', function () {
    Http::fake();

    $channel = SmsChannelFactory::make(makeSmsConfig('mock'));

    expect($channel)->toBeInstanceOf(MockSmsChannel::class);
    expect($channel->available())->toBeTrue();

    $result = $channel->send('13800138000', 'SMS_1', ['code' => '123456']);

    expect($result->ok)->toBeTrue();
    expect($result->providerMessageId)->toStartWith('mock-');

    Http::assertNothingSent();
});

test('TC-SMS-CH-002 阿里云凭证齐备 → 真实适配器', function () {
    Http::fake();

    $config = makeSmsConfig('aliyun', ['access_key_secret' => 'secret-123']);

    expect($config->credentialsComplete())->toBeTrue();
    expect(SmsChannelFactory::make($config))->toBeInstanceOf(AliyunSmsChannel::class);
});

test('TC-SMS-CH-003 阿里云凭证缺失 → 非生产环境回退 Mock 并告警', function () {
    Log::spy();
    Http::fake();

    $config = makeSmsConfig('aliyun', ['access_key_id' => null]);

    $channel = SmsChannelFactory::make($config);

    expect($channel)->toBeInstanceOf(MockSmsChannel::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === 'sms.channel.fallback'
            && $context['provider'] === 'aliyun'
            // 未填 Secret 时缺失项是两项，故用包含判断而非相等
            && str_contains($context['missing'], 'access_key_id'));
});

test('TC-SMS-CH-004 阿里云凭证缺失 → 生产环境 fail-closed 抛错（绝不假成功）', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        $config = makeSmsConfig('aliyun', ['access_key_id' => null]);

        SmsChannelFactory::make($config);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('生产环境拒绝回退 Mock 渠道');

        return;
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    $this->fail('生产环境凭证缺失时应当抛错，而不是回退 Mock');
});

test('TC-SMS-CH-005 未落地服务商与未知 provider 一律抛错', function () {
    expect(fn () => SmsChannelFactory::make(makeSmsConfig('tencent')))
        ->toThrow(RuntimeException::class, '腾讯云短信适配器将在二期提供');

    expect(fn () => SmsChannelFactory::make(makeSmsConfig('unknown')))
        ->toThrow(RuntimeException::class, '未知短信服务商：unknown');
});

test('TC-SMS-CH-006 阿里云 Code=OK → 成功并带回 BizId', function () {
    Http::fake(['*' => Http::response([
        'Code' => 'OK',
        'Message' => 'OK',
        'BizId' => '900619746936498440^0',
        'RequestId' => 'F655A8D5-B967-440B-8683-DAD6FF8DE990',
    ], 200)]);

    $result = (new AliyunSmsChannel('LTAI_test', 'secret', 'CubeShop'))
        ->send('13800138000', 'SMS_1', ['code' => '123456']);

    expect($result->ok)->toBeTrue();
    expect($result->providerMessageId)->toBe('900619746936498440^0');
    expect($result->latencyMs)->toBeInt();
});

test('TC-SMS-CH-007 阿里云业务错误 → 失败 + 中文可读提示', function () {
    Http::fake(['*' => Http::response([
        'Code' => 'isv.BUSINESS_LIMIT_CONTROL',
        'Message' => '触发小时级流控',
    ], 200)]);

    $result = (new AliyunSmsChannel('LTAI_test', 'secret', 'CubeShop'))
        ->send('13800138000', 'SMS_1', ['code' => '123456']);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('isv.BUSINESS_LIMIT_CONTROL');
    expect($result->errorMsg)->toContain('流控');
});

test('TC-SMS-CH-008 HTTP 非 200 与连接异常都不抛异常，转成失败结果', function () {
    Http::fake(['*' => Http::response('Bad Gateway', 502)]);

    $failed = (new AliyunSmsChannel('LTAI_test', 'secret', 'CubeShop'))
        ->send('13800138000', 'SMS_1', []);

    expect($failed->ok)->toBeFalse();
    expect($failed->errorMsg)->toContain('502');

    Http::fake(['*' => fn () => throw new ConnectionException('连接超时')]);

    $timeout = (new AliyunSmsChannel('LTAI_test', 'secret', 'CubeShop'))
        ->send('13800138000', 'SMS_1', []);

    expect($timeout->ok)->toBeFalse();
    expect($timeout->errorCode)->toBe('http_error');
    expect($timeout->errorMsg)->toContain('连接超时');
});

test('TC-SMS-CH-009 请求形状：参数走 query、空 body、无 Content-Type、带 Authorization', function () {
    Http::fake(['*' => Http::response(['Code' => 'OK', 'BizId' => 'biz-1'], 200)]);

    (new AliyunSmsChannel('LTAI_test', 'secret', 'CubeShop签名'))
        ->send('13800138000', 'SMS_123', ['code' => '123456']);

    Http::assertSent(function (Request $request) {
        $url = $request->url();

        // 业务参数在 query string 里（RPC 风格），且中文签名已编码
        expect($url)->toContain('PhoneNumbers=13800138000');
        expect($url)->toContain('TemplateCode=SMS_123');
        expect($url)->toContain('SignName='.rawurlencode('CubeShop签名'));
        expect($url)->not->toContain('CubeShop签名');

        // ⚠️ 空 body 且不带 Content-Type：带上了就会被网关判签名不一致
        expect($request->body())->toBe('');
        expect($request->hasHeader('Content-Type'))->toBeFalse();

        // V3 签名头齐备
        expect($request->header('Authorization')[0] ?? '')->toStartWith('ACS3-HMAC-SHA256 Credential=LTAI_test,');
        expect($request->header('x-acs-action')[0] ?? '')->toBe('SendSms');
        expect($request->header('x-acs-version')[0] ?? '')->toBe('2017-05-25');
        expect($request->header('x-acs-content-sha256')[0] ?? '')->toBe(hash('sha256', ''));

        return true;
    });
});

test('TC-SMS-CH-010 凭证不完整时适配器直接失败，不发请求', function () {
    Http::fake();

    $result = (new AliyunSmsChannel('', '', ''))->send('13800138000', 'SMS_1', []);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('missing_credentials');

    Http::assertNothingSent();
});
