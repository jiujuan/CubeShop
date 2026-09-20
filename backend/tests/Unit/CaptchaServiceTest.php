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
        expect(preg_match('/^[A-Z2-9]{5}$/', $result['debug_code']))->toBe(1)
            // 不含易混淆字符 0/O/1/I
            ->and($result['debug_code'])->not->toMatch('/[0O1I]/');
    }
});

// CAP-U-09 位数与字符构成：5 位且字母数字混合（抗暴力破解的核心）
test('验证码恒为 5 位且必须同时含字母与数字', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);

    for ($i = 0; $i < 60; $i++) {
        $code = $service->generate($i % 2 === 0 ? 'admin' : 'web')['debug_code'];

        expect(strlen($code))->toBe(5)
            ->and($code)->toMatch('/^[A-Z2-9]{5}$/')
            ->and($code)->toMatch('/[A-Z]/')
            ->and($code)->toMatch('/[2-9]/');
    }
});

// CAP-U-10 字符集：剔除易混淆字符，且组合空间足够大
test('字符集剔除易混淆字符，组合空间不低于 32^5', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);

    for ($i = 0; $i < 60; $i++) {
        // 剔除 0/O 与 1/I 两对易混淆字符（大写 L 与 1 不易混，保留以维持字符集规模）
        expect($service->generate()['debug_code'])->not->toMatch('/[0O1I]/');
    }

    // 24 字母 + 8 数字 = 32 个字符，5 位 → 3355 万组合（原 4 位仅约 104 万）
    expect(32 ** CaptchaService::LENGTH)->toBeGreaterThan(30_000_000);
});

// CAP-U-11 干扰强度：噪点/直线/曲线/顶层遮挡达到设计数量，且 SVG 可被解析
test('SVG 干扰元素达到设计强度且结构合法', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);

    foreach (['admin', 'web'] as $scene) {
        $svg = base64_decode(substr($service->generate($scene)['image'], strlen('data:image/svg+xml;base64,')));

        // 结构合法（否则 <img> 直接渲染不出来）
        expect(simplexml_load_string($svg))->not->toBeFalse();

        // 底层 + 顶层噪点 ≥ 80；直线 ≥ 7；贝塞尔曲线 ≥ 5；短弧 ≥ 4
        expect(substr_count($svg, '<circle'))->toBeGreaterThanOrEqual(80)
            ->and(substr_count($svg, '<line'))->toBeGreaterThanOrEqual(7)
            ->and(substr_count($svg, '<path'))->toBeGreaterThanOrEqual(9)
            // 字符层：5 位 ×（重影 + 本体）= 10 个 text
            ->and(substr_count($svg, '<text'))->toBe(10)
            // 波纹扭曲滤镜 + 非等距槽位（skewX 抖动）
            ->and($svg)->toContain('feDisplacementMap')
            ->and($svg)->toContain('skewX');
    }
});

// CAP-U-12 顶层遮挡画在字符之后（OCR 最难处理的"字符被穿过"）
test('穿越字符的遮挡线画在字符层之后', function () {
    config(['app.debug' => true]);
    $svg = base64_decode(substr(app(CaptchaService::class)->generate()['image'], strlen('data:image/svg+xml;base64,')));

    // 最后一个 </g> 即字符层（<g filter="url(#warp)">）的闭合，其后才是顶层遮挡
    $charLayerEnd = strrpos($svg, '</g>');
    $lastCircle = strrpos($svg, '<circle');

    expect($charLayerEnd)->not->toBeFalse()
        ->and($lastCircle)->toBeGreaterThan($charLayerEnd);
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

    // 注意：错误码也要凑够 5 位 —— 位数不足会先被位数守卫拦下（不消耗图片），
    // 走不到"销毁"这一步（该分支由 CAP-U-13 覆盖）。
    expect($service->verify($result['captcha_id'], 'XXXXX'))->toBeFalse()
        ->and(Cache::has('captcha:'.$result['captcha_id']))->toBeFalse();
});

// CAP-U-05 不存在的 captcha_id
test('不存在的 captcha_id 校验失败', function () {
    expect(app(CaptchaService::class)->verify('not-exist-uuid', 'ABCDE'))->toBeFalse();
});

// CAP-U-13 位数校验：服务层兜底，位数不符一律拒绝（防调用方漏校验）
test('验证码位数不符一律拒绝，且明文正确也不放行', function () {
    config(['app.debug' => true]);
    $service = app(CaptchaService::class);
    $result = $service->generate();
    $code = $result['debug_code'];

    // 4 位（少输）与 6 位（多输）都拒绝
    expect($service->verify($result['captcha_id'], substr($code, 0, 4)))->toBeFalse()
        ->and($service->verify($result['captcha_id'], $code.'X'))->toBeFalse();

    // 位数校验发生在 pull 之前：这张图尚未被消耗，正确位数仍可通行
    expect($service->verify($result['captcha_id'], $code))->toBeTrue();

    // 正确明文被截断成 4 位后同样拒绝（即便前缀完全匹配）
    $another = $service->generate();
    expect($service->verify($another['captcha_id'], substr($another['debug_code'], 0, 4)))->toBeFalse();
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
