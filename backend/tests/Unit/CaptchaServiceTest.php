<?php

use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// CAP-U-01 生成：返回 id 与图片，debug 环境附带明文
test('generate 返回验证码结构', function () {
    config(['app.debug' => true]);
    $result = app(CaptchaService::class)->generate();

    expect($result)->toHaveKeys(['captcha_id', 'image', 'expires_in'])
        ->and($result['image'])->toStartWith('data:image/svg+xml;base64,')
        ->and($result['expires_in'])->toBe(300);

    if (array_key_exists('debug_code', $result)) {
        expect(preg_match('/^[A-Z2-9]{4}$/', $result['debug_code']))->toBe(1)
            // 不含易混淆字符 0/O/1/I
            ->and($result['debug_code'])->not->toMatch('/[0O1I]/');
    }
});

// CAP-U-02 校验正确后销毁（一次性）
test('verify 正确后销毁，二次校验失败', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);
    $result = $service->generate();

    expect($service->verify($result['captcha_id'], $result['debug_code']))->toBeTrue()
        ->and($service->verify($result['captcha_id'], $result['debug_code']))->toBeFalse();
});

// CAP-U-03 大小写与空白容忍
test('verify 大小写不敏感且容忍首尾空白', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);
    $result = $service->generate();

    expect($service->verify($result['captcha_id'], ' '.$result['debug_code'].' '))->toBeTrue();
});

// CAP-U-04 错误验证码拒绝
test('verify 错误码返回 false 且销毁原验证码', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);
    $result = $service->generate();

    expect($service->verify($result['captcha_id'], 'XXXX'))->toBeFalse()
        ->and(Cache::has('captcha:'.$result['captcha_id']))->toBeFalse();
});

// CAP-U-05 不存在的 captcha_id
test('不存在的 captcha_id 校验失败', function () {
    expect(app(CaptchaService::class)->verify('not-exist-uuid', 'ABCD'))->toBeFalse();
});
