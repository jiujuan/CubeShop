<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 图形验证码服务（登录 / 注册用）
 *
 * - generate($scene) 按 scene 生成 4 位验证码，返回 captcha_id + SVG 图片（base64）
 *   - admin（默认）：后台管理端登录，浅蓝底 + 蓝/青/紫配色 + 直线干扰
 *   - web：用户端登录/注册，暖色底 + 绿/橙/红配色 + 曲线干扰，风格与管理端明显区分
 * - verify() 校验并销毁（一次性）
 * - 存储走 Cache（database 驱动即可降级，无 Redis 依赖）
 */
class CaptchaService
{
    private const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'captcha:';

    /** 支持的场景；未识别的 scene 一律回落 admin */
    public const SCENES = ['admin', 'web'];

    public function generate(string $scene = 'admin'): array
    {
        $scene = in_array($scene, self::SCENES, true) ? $scene : 'admin';

        $code = Str::upper(Str::random(4));
        // 去除易混淆字符
        $code = str_replace(['0', 'O', '1', 'I'], ['2', 'A', '3', 'J'], $code);
        $captchaId = Str::uuid()->toString();

        Cache::put(self::CACHE_PREFIX.$captchaId, $code, self::TTL_SECONDS);

        $result = [
            'captcha_id' => $captchaId,
            'image' => 'data:image/svg+xml;base64,'.base64_encode(
                $scene === 'web' ? $this->renderWebSvg($code) : $this->renderSvg($code)
            ),
            'expires_in' => self::TTL_SECONDS,
        ];

        // 仅本地调试（APP_DEBUG=true）附带明文，便于联调与自动化测试；生产不返回
        if (config('app.debug')) {
            $result['debug_code'] = $code;
        }

        return $result;
    }

    public function verify(string $captchaId, string $code): bool
    {
        $key = self::CACHE_PREFIX.$captchaId;
        $expected = Cache::pull($key); // 一次性

        if (! $expected) {
            return false;
        }

        return Str::upper(trim($code)) === $expected;
    }

    private function renderSvg(string $code): string
    {
        $width = 120;
        $height = 44;
        $chars = [];

        // 字符随机旋转与颜色
        $colors = ['#1677ff', '#0958d9', '#4096ff', '#13c2c2', '#722ed1'];
        for ($i = 0; $i < mb_strlen($code); $i++) {
            $char = $code[$i];
            $rotate = random_int(-20, 20);
            $x = 14 + $i * 26;
            $y = 30 + random_int(-3, 3);
            $color = $colors[array_rand($colors)];
            $chars[] = sprintf(
                '<text x="%d" y="%d" font-size="26" font-family="Arial, sans-serif" font-weight="bold" fill="%s" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $color, $rotate, $x, $y, $char,
            );
        }

        // 干扰线
        $lines = [];
        for ($i = 0; $i < 4; $i++) {
            $lines[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" opacity="0.4"/>',
                random_int(0, $width), random_int(0, $height),
                random_int(0, $width), random_int(0, $height),
                $colors[array_rand($colors)],
            );
        }

        // 干扰点
        $dots = [];
        for ($i = 0; $i < 30; $i++) {
            $dots[] = sprintf(
                '<circle cx="%d" cy="%d" r="1" fill="%s" opacity="0.5"/>',
                random_int(0, $width), random_int(0, $height), $colors[array_rand($colors)],
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#f5f8ff"/>%s%s%s</svg>',
            $width, $height, $width, $height,
            implode('', $lines), implode('', $dots), implode('', $chars),
        );
    }

    /**
     * 用户端（web）风格：暖色底、绿/橙/红配色、贝塞尔曲线干扰、字符斜体，
     * 与管理端风格明显区分（尺寸保持 120x44，前端布局无需调整）。
     */
    private function renderWebSvg(string $code): string
    {
        $width = 120;
        $height = 44;
        $chars = [];

        $colors = ['#16a34a', '#ea580c', '#e11d48', '#d97706', '#0d9488'];
        for ($i = 0; $i < mb_strlen($code); $i++) {
            $char = $code[$i];
            $rotate = random_int(-25, 25);
            $x = 14 + $i * 26;
            $y = 30 + random_int(-3, 3);
            $size = 24 + random_int(0, 4);
            $color = $colors[array_rand($colors)];
            $chars[] = sprintf(
                '<text x="%d" y="%d" font-size="%d" font-family="Georgia, serif" font-style="italic" font-weight="bold" fill="%s" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $size, $color, $rotate, $x, $y, $char,
            );
        }

        // 贝塞尔曲线干扰线（与管理端直线区分）
        $curves = [];
        for ($i = 0; $i < 3; $i++) {
            $color = $colors[array_rand($colors)];
            $y1 = random_int(4, 40);
            $y2 = random_int(4, 40);
            $curves[] = sprintf(
                '<path d="M %d %d Q %d %d, %d %d T %d %d" fill="none" stroke="%s" stroke-width="1.2" opacity="0.45"/>',
                random_int(0, 20), $y1,
                random_int(30, 60), random_int(2, 42),
                random_int(60, 90), $y2,
                random_int(100, 120), random_int(2, 42),
                $color,
            );
        }

        // 干扰点（空心圆 + 实心点混合）
        $dots = [];
        for ($i = 0; $i < 24; $i++) {
            $cx = random_int(0, $width);
            $cy = random_int(0, $height);
            $color = $colors[array_rand($colors)];
            $dots[] = $i % 3 === 0
                ? sprintf('<circle cx="%d" cy="%d" r="2" fill="none" stroke="%s" stroke-width="0.8" opacity="0.5"/>', $cx, $cy, $color)
                : sprintf('<circle cx="%d" cy="%d" r="1" fill="%s" opacity="0.5"/>', $cx, $cy, $color);
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><defs><linearGradient id="webbg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%%" stop-color="#fffbeb"/><stop offset="100%%" stop-color="#fef2f2"/></linearGradient></defs><rect width="100%%" height="100%%" fill="url(#webbg)"/>%s%s%s</svg>',
            $width, $height, $width, $height,
            implode('', $curves), implode('', $dots), implode('', $chars),
        );
    }
}
