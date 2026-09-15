<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 图形验证码服务（登录用，见后台登录页原型）
 *
 * - generate() 生成 4 位验证码，返回 captcha_id + SVG 图片（base64）
 * - verify() 校验并销毁（一次性）
 * - 存储走 Cache（database 驱动即可降级，无 Redis 依赖）
 */
class CaptchaService
{
    private const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'captcha:';

    public function generate(): array
    {
        $code = Str::upper(Str::random(4));
        // 去除易混淆字符
        $code = str_replace(['0', 'O', '1', 'I'], ['2', 'A', '3', 'J'], $code);
        $captchaId = Str::uuid()->toString();

        Cache::put(self::CACHE_PREFIX.$captchaId, $code, self::TTL_SECONDS);

        $result = [
            'captcha_id' => $captchaId,
            'image' => 'data:image/svg+xml;base64,'.base64_encode($this->renderSvg($code)),
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
}
