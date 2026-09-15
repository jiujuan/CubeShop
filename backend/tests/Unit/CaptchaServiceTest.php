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

// CAP-U-06 web 场景风格与管理端不同
test('web 场景生成的图片风格与管理端不同', function () {
    $service = app(CaptchaService::class);

    $admin = $service->generate('admin');
    $web = $service->generate('web');

    $adminSvg = base64_decode(substr($admin['image'], strlen('data:image/svg+xml;base64,')));
    $webSvg = base64_decode(substr($web['image'], strlen('data:image/svg+xml;base64,')));

    // 管理端：浅蓝纯色底、直线干扰
    expect($adminSvg)->toContain('#f5f8ff')->toContain('<line');
    // 用户端：暖色渐变底、曲线干扰、斜体字符
    expect($webSvg)->toContain('linearGradient')->toContain('<path')->toContain('font-style="italic"')
        ->not->toContain('#f5f8ff');
});

// CAP-U-07 非法 scene 回落 admin
test('非法 scene 回落管理端样式', function () {
    $service = app(CaptchaService::class);
    $result = $service->generate('hacker');

    $svg = base64_decode(substr($result['image'], strlen('data:image/svg+xml;base64,')));
    expect($svg)->toContain('#f5f8ff');
});

// CAP-U-08 接口层 scene 透传
test('POST /auth/captcha 按 scene 返回不同风格', function () {
    $admin = $this->postJson('/api/auth/captcha');
    $web = $this->postJson('/api/auth/captcha', ['scene' => 'web']);

    $adminSvg = base64_decode(substr($admin->json('data.image'), strlen('data:image/svg+xml;base64,')));
    $webSvg = base64_decode(substr($web->json('data.image'), strlen('data:image/svg+xml;base64,')));

    expect($admin->json('code'))->toBe(0)
        ->and($web->json('code'))->toBe(0)
        ->and($adminSvg)->toContain('#f5f8ff')
        ->and($webSvg)->toContain('linearGradient');
});
