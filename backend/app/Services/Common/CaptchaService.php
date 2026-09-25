<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 图形验证码服务（登录 / 注册用）
 *
 * - generate($scene) 生成 **5 位**字母数字混合验证码，返回 captcha_id + SVG 图片（base64）
 *   - admin（默认）：后台管理端登录，冷色（蓝/青/紫）渐变底 + 无衬线正体
 *   - web：用户端登录/注册，暖色渐变底 + 衬线斜体，风格与管理端明显区分
 *
 * - 抗机器识别（SEC）：
 *   - 空间：5 位 × 32 字符集 = 32^5 ≈ 3355 万组合（原 4 位约 104 万，提升 32 倍）
 *   - 字符：随机旋转 ±22° / 斜切 ±8° / 字号 22~27（一律实心填充）+
 *     错位重影（+1.2px、opacity 0.18）+ 整组 feDisplacementMap 波纹扭曲（scale 1.2~2.0）
 *   - 排布：字符槽位带 ±2px 抖动，破坏等距带来的一次性分割
 *   - 干扰：底层 80 噪点（实心/空心混合）+ 7 直线 + 5 曲线 + 4 短弧 + 斜纹底纹
 *   - 遮挡：字符**之上**再叠 3 条穿越细线与 15 个噪点 —— 被遮挡的字符是 OCR 连通域分析的死穴
 *
 * - verify() 校验并销毁（一次性：错误也销毁，不允许拿同一张图反复试）
 * - 存储走 Cache（database 驱动即可降级，无 Redis 依赖）
 *
 * ⚠️ 干扰强度是可读性与抗破解的权衡：上层遮挡线 opacity 控制在 0.2~0.3、重影 0.22，
 *    再高人眼也难认；要继续加难度应优先加位数（LENGTH）而不是加噪声。
 */
class CaptchaService
{
    private const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'captcha:';

    /** 支持的场景；未识别的 scene 一律回落 admin */
    public const SCENES = ['admin', 'web'];

    /** 验证码位数 */
    public const LENGTH = 5;

    /**
     * 可用字符集：剔除 0/O/1/I 等易混淆字符（24 字母 + 8 数字 = 32 个）
     * 组合数 32^5 = 33,554,432
     */
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const DIGITS = '23456789';

    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const WIDTH = 140;

    private const HEIGHT = 44;

    public function generate(string $scene = 'admin'): array
    {
        $scene = in_array($scene, self::SCENES, true) ? $scene : 'admin';

        $code = $this->randomCode();
        $captchaId = Str::uuid()->toString();

        Cache::put(self::CACHE_PREFIX.$captchaId, $code, self::TTL_SECONDS);

        $result = [
            'captcha_id' => $captchaId,
            'image' => 'data:image/svg+xml;base64,'.base64_encode($this->renderSvg($code, $scene)),
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
        // 位数不符直接判失败：即便明文碰巧对上也不放行，且不必消耗这张图。
        // 控制器另有一层 `size:LENGTH` 校验负责返回 422 明细，这里是兜底（防调用方漏校验）。
        if (mb_strlen(trim($code)) !== self::LENGTH) {
            return false;
        }

        $key = self::CACHE_PREFIX.$captchaId;
        $expected = Cache::pull($key); // 一次性

        if (! $expected) {
            return false;
        }

        return Str::upper(trim($code)) === $expected;
    }

    /**
     * 随机验证码：从无歧义字符集取值，并**保证字母与数字混合**
     * （纯字母/纯数字既降低组合数，也让 OCR 更容易按同类字形建模）
     */
    private function randomCode(): string
    {
        $chars = [];
        for ($i = 0; $i < self::LENGTH; $i++) {
            $chars[] = self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
        }

        if (! array_intersect($chars, str_split(self::DIGITS))) {
            // 全是字母 → 随机一位换成数字
            $chars[array_rand($chars)] = self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
        }

        if (! array_intersect($chars, str_split(self::LETTERS))) {
            // 全是数字 → 随机一位换成字母
            $chars[array_rand($chars)] = self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
        }

        return implode('', $chars);
    }

    private function renderSvg(string $code, string $scene): string
    {
        $isWeb = $scene === 'web';

        $colors = $isWeb
            ? ['#16a34a', '#ea580c', '#e11d48', '#d97706', '#0d9488', '#b45309']
            : ['#1677ff', '#0958d9', '#4096ff', '#13c2c2', '#722ed1', '#531dab'];

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">'
            .'%s'
            .'<rect width="100%%" height="100%%" fill="url(#bg)"/>'
            .'<rect width="100%%" height="100%%" fill="url(#stripe)"/>'
            .'%s'
            .'<g filter="url(#warp)">%s</g>'
            .'%s'
            .'</svg>',
            self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT,
            $this->defs($isWeb),
            $this->noiseLayer($colors),
            $this->characters($code, $colors, $isWeb),
            $this->overlay($colors),
        );
    }

    /** 渐变底 + 斜纹底纹 + 波纹扭曲滤镜（seed/角度/强度每次随机，同一张图不可复用特征） */
    private function defs(bool $isWeb): string
    {
        return sprintf(
            '<defs>'
            .'<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0%%" stop-color="%s"/><stop offset="100%%" stop-color="%s"/>'
            .'</linearGradient>'
            .'<pattern id="stripe" width="7" height="7" patternUnits="userSpaceOnUse" patternTransform="rotate(%d)">'
            .'<line x1="0" y1="0" x2="0" y2="7" stroke="#94a3b8" stroke-width="1" opacity="0.10"/>'
            .'</pattern>'
            .'<filter id="warp" x="-15%%" y="-25%%" width="130%%" height="150%%">'
            .'<feTurbulence type="fractalNoise" baseFrequency="%s" numOctaves="2" seed="%d" result="n"/>'
            .'<feDisplacementMap in="SourceGraphic" in2="n" scale="%s" xChannelSelector="R" yChannelSelector="G"/>'
            .'</filter>'
            .'</defs>',
            $isWeb ? '#fffbeb' : '#f5f8ff',
            $isWeb ? '#fef2f2' : '#eaf1ff',
            random_int(20, 55),
            $this->decimal(random_int(20, 45), 3),
            random_int(1, 9999),
            // 扭曲幅度：再大字符就粘连了，人眼也读不出来
            $this->decimal(random_int(12, 20), 1),
        );
    }

    /** 底层干扰：噪点 + 直线 + 贝塞尔曲线 + 短弧（均在字符之下） */
    private function noiseLayer(array $colors): string
    {
        $parts = [];

        // 噪点：实心 / 空心混合，半径与透明度随机
        for ($i = 0; $i < 80; $i++) {
            $cx = random_int(0, self::WIDTH);
            $cy = random_int(0, self::HEIGHT);
            $color = $colors[array_rand($colors)];
            $opacity = $this->decimal(random_int(20, 60), 2);

            $parts[] = $i % 4 === 0
                ? sprintf(
                    '<circle cx="%d" cy="%d" r="%s" fill="none" stroke="%s" stroke-width="0.8" opacity="%s"/>',
                    $cx, $cy, $this->decimal(random_int(8, 20), 1), $color, $opacity,
                )
                : sprintf(
                    '<circle cx="%d" cy="%d" r="%s" fill="%s" opacity="%s"/>',
                    $cx, $cy, $this->decimal(random_int(5, 16), 1), $color, $opacity,
                );
        }

        // 直线干扰
        for ($i = 0; $i < 7; $i++) {
            $parts[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="%s" opacity="%s"/>',
                random_int(-5, self::WIDTH), random_int(0, self::HEIGHT),
                random_int(0, self::WIDTH + 5), random_int(0, self::HEIGHT),
                $colors[array_rand($colors)],
                $this->decimal(random_int(5, 14), 1),
                $this->decimal(random_int(18, 45), 2),
            );
        }

        // 三次贝塞尔曲线干扰
        for ($i = 0; $i < 5; $i++) {
            $parts[] = sprintf(
                '<path d="M %d %d C %d %d, %d %d, %d %d" fill="none" stroke="%s" stroke-width="%s" opacity="%s"/>',
                random_int(0, 30), random_int(0, self::HEIGHT),
                random_int(20, 70), random_int(-10, self::HEIGHT + 10),
                random_int(70, 120), random_int(-10, self::HEIGHT + 10),
                random_int(110, self::WIDTH + 5), random_int(0, self::HEIGHT),
                $colors[array_rand($colors)],
                $this->decimal(random_int(6, 16), 1),
                $this->decimal(random_int(18, 45), 2),
            );
        }

        // 短弧干扰
        for ($i = 0; $i < 4; $i++) {
            $x = random_int(4, self::WIDTH - 24);
            $y = random_int(6, self::HEIGHT - 6);
            $r = random_int(8, 22);

            $parts[] = sprintf(
                '<path d="M %d %d A %d %d 0 0 1 %d %d" fill="none" stroke="%s" stroke-width="%s" opacity="%s"/>',
                $x, $y, $r, $r, $x + random_int(6, 18), $y + random_int(-8, 8),
                $colors[array_rand($colors)],
                $this->decimal(random_int(6, 14), 1),
                $this->decimal(random_int(18, 40), 2),
            );
        }

        return implode('', $parts);
    }

    /**
     * 字符层：每位独立随机旋转 / 斜切 / 字号 / 基线偏移，并带一层错位重影。
     * 20% 概率用描边空心字（弱化实心笔画的连通性）；web 场景加斜体。
     */
    private function characters(string $code, array $colors, bool $italic): string
    {
        $font = $italic ? 'Georgia, serif' : 'Arial, Helvetica, sans-serif';
        $style = $italic ? ' font-style="italic"' : '';
        $parts = [];
        $x = 13;

        for ($i = 0; $i < mb_strlen($code); $i++) {
            $char = $code[$i];
            $size = random_int(22, 27);
            $rotate = random_int(-22, 22);
            $skew = random_int(-8, 8);
            $y = 30 + random_int(-4, 4);
            $color = $colors[array_rand($colors)];
            $ghost = $colors[array_rand($colors)];

            // ⚠️ 一律实心填充：曾试过 20% 概率用描边空心字，但 22~27px 字号下 1.1px 的
            //    空心笔画被 80 噪点 + 穿越线一盖就断，人眼同样认不出（人工登录实测失败）。
            //    抗 OCR 靠的是穿越线与噪点，不是空心字 —— 空心字是「伤敌八百自损一千」。
            $paint = sprintf('fill="%s"', $color);

            $parts[] = sprintf(
                '<g transform="translate(%d %d) rotate(%d) skewX(%d)">'
                .'<text x="0" y="0" font-size="%d" font-family="%s" font-weight="bold"%s fill="%s" opacity="0.18" transform="translate(1.2 1.2)">%s</text>'
                .'<text x="0" y="0" font-size="%d" font-family="%s" font-weight="bold"%s %s>%s</text>'
                .'</g>',
                $x, $y, $rotate, $skew,
                $size, $font, $style, $ghost, $char,
                $size, $font, $style, $paint, $char,
            );

            // 槽位 ±2px 抖动：字符不等距，破坏一次性分割
            $x += 25 + random_int(-2, 2);
        }

        return implode('', $parts);
    }

    /** 顶层遮挡：画在字符**之上**，低不透明度，人眼可辨但显著干扰 OCR 连通域分析 */
    private function overlay(array $colors): string
    {
        $parts = [];

        for ($i = 0; $i < 3; $i++) {
            $y = random_int(8, self::HEIGHT - 8);

            $parts[] = sprintf(
                '<path d="M -5 %d Q %d %d, %d %d" fill="none" stroke="%s" stroke-width="1" opacity="%s"/>',
                $y,
                intdiv(self::WIDTH, 2), $y + random_int(-12, 12),
                self::WIDTH + 5, $y + random_int(-6, 6),
                $colors[array_rand($colors)],
                $this->decimal(random_int(20, 30), 2),
            );
        }

        for ($i = 0; $i < 15; $i++) {
            $parts[] = sprintf(
                '<circle cx="%d" cy="%d" r="%s" fill="%s" opacity="%s"/>',
                random_int(0, self::WIDTH), random_int(0, self::HEIGHT),
                $this->decimal(random_int(5, 14), 1),
                $colors[array_rand($colors)],
                $this->decimal(random_int(22, 38), 2),
            );
        }

        return implode('', $parts);
    }

    /** 把 random_int 出的整数按指定小数位格式化（如 45/3 → "0.045"），避免 %f 的精度噪音 */
    private function decimal(int $value, int $scale): string
    {
        return number_format($value / (10 ** $scale), $scale, '.', '');
    }
}
