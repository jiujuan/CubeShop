<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 图片 URL / 存储路径 的唯一换算入口（媒体治理 P0）。
 *
 * 背景：历史上图片以 `Storage::disk('public')->url($path)` 的**绝对 URL** 直接落库
 * （形如 `http://localhost:8000/storage/uploads/products/20260918/x.png`）。
 * 一旦部署到正式域名，全部历史图片 404 —— 根因是「URL 在写入时就生成好了」，域名被写死进数据。
 *
 * 修正后的口径：
 * - **库里只存相对路径**（`uploads/products/20260918/x.png`），与域名无关；
 * - **URL 在读取时才拼**，出口（Accessor / Resource）经 {@see self::to()} 得到绝对地址；
 * - 由此换 `APP_URL`、上 CDN、切 S3/OSS 都**零数据迁移**。
 *
 * 三种形态与两个方向：
 *
 * | 形态 | 例子 | 说明 |
 * |---|---|---|
 * | 存储态 | `uploads/x.png` | 库里的标准写法（相对 disk root） |
 * | 根相对 | `/storage/uploads/x.png` | HTML 正文里用（含资源名的 URL，浏览器可直接解析） |
 * | 绝对态 | `http://host/storage/uploads/x.png` | 出口给前端的形态 |
 *
 * - {@see self::toPath()}：任意形态 → 存储态（**写**方向）
 * - {@see self::to()}   ：任意形态 → 绝对态（**读**方向）
 * - {@see self::normalizeEmbedded()} / {@see self::absoluteEmbedded()}：处理 HTML/Markdown 正文里的内联图
 *
 * ⚠️ 外链（图床、第三方域名）与 `data:` base64 一律**原样透传**，不做任何改写 ——
 * 本机 disk 管不到的东西，改写既是错事也是隐患。
 */
final class MediaUrl
{
    /** 图片统一存放的 disk（切 S3/OSS 时只改这里与 filesystems.php） */
    public const DISK = 'public';

    /**
     * 任意形态 → 可访问的绝对 URL（读取方向）
     *
     * - null / 空串：原样返回（调用方好判空）
     * - 外链（http/https/协议相对）、`data:` base64：原样返回
     * - 根相对 `/storage/...` 与存储态 `uploads/...`：拼上 disk 的 url
     */
    public static function to(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (self::isForeign($value)) {
            return $value;
        }

        $path = self::toRelative($value);
        if ($path === '') {
            return $value;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    /**
     * {@see self::to()} 的保守版本：**只**转换「看起来像本站图片」的字符串
     *
     * 用于嵌套结构（CMS `blocks` 等）—— 那里的字符串可能是普通文案，
     * 与其无缘无故拼个域名上去，不如放过它。
     */
    public static function toIfAsset(?string $value): ?string
    {
        if ($value === null || $value === '' || ! self::isAssetCandidate($value)) {
            return $value;
        }

        return self::to($value);
    }

    /** {@see self::toPath()} 的保守版本，配合 {@see self::toIfAsset()} 用于嵌套结构 */
    public static function toPathIfAsset(?string $value): ?string
    {
        if ($value === null || $value === '' || ! self::isAssetCandidate($value)) {
            return $value;
        }

        return self::toPath($value);
    }

    /**
     * 列表 / JSON 数组形态：逐元素 {@see self::to()}
     *
     * @param  array<int, mixed>|null  $values
     * @return array<int, mixed>|null
     */
    public static function toMany(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map(
            fn (mixed $v): mixed => is_string($v) ? self::to($v) : $v,
            $values,
        );
    }

    /**
     * 任意形态 → 库里的存储态相对路径（写入方向）
     *
     * - 外链 / `data:` base64：原样返回
     * - `http(s)://host/storage/uploads/x.png` → `uploads/x.png`
     * - `/storage/uploads/x.png` → `uploads/x.png`
     * - 已经是 `uploads/x.png`：原样返回（幂等）
     */
    public static function toPath(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (self::isForeign($value)) {
            return self::isOwnAsset($value) ? self::stripAssetPrefix($value) : $value;
        }

        $relative = self::stripAssetPrefix($value);

        return $relative !== '' ? $relative : $value;
    }

    /**
     * JSON 数组形态：逐元素 {@see self::toPath()}
     *
     * @param  array<int, mixed>|null  $values
     * @return array<int, mixed>|null
     */
    public static function toPathMany(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map(
            fn (mixed $v): mixed => is_string($v) ? self::toPath($v) : $v,
            $values,
        );
    }

    /**
     * HTML / Markdown 正文里内联的本站图片 → 根相对形态（写入方向）
     *
     * 例：`![a](http://localhost:8000/storage/uploads/cms/x.png)` → `![a](/storage/uploads/cms/x.png)`
     *
     * ⚠️ 只改写**含 `/storage/` 资源前缀**的地址（public disk 的强特征）；
     * 其它外链图（图床等）保持不动。
     */
    public static function normalizeEmbedded(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return (string) preg_replace_callback(
            '#(?:https?:)?//[^"\'\s)\]]+?(/storage/)#i',
            function (array $m): string {
                $absolute = 'http:'.$m[0];

                return self::isOwnAsset($absolute) ? $m[1] : $m[0];
            },
            $text,
        );
    }

    /**
     * HTML / Markdown 正文里内联的本站图片 → 绝对 URL（读取方向）
     *
     * 生产部署后 APP_URL 变化、或将来 CDN 域名不同，都靠这里统一出口；
     * 因为前端只代理 `/api`，`/storage/...` 这种根相对地址在浏览器侧会打到本地端口而 404。
     */
    public static function absoluteEmbedded(?string $text): ?string
    {
        if ($text === null || $text === '' || ! str_contains($text, '/storage/')) {
            return $text;
        }

        // disk 的根 URL 自带 `/storage` 尾巴，先剥掉再拼，避免出现 /storage/storage/ 的双前缀
        $root = rtrim(Storage::disk(self::DISK)->url(''), '/');
        $prefix = self::assetPrefix();
        $base = str_ends_with($root, $prefix) ? substr($root, 0, -strlen($prefix)) : $root;

        // 覆盖三种内联写法：HTML 属性 src="/storage/…"、Markdown ![](/storage/…)、尖括号 ![](</storage/…>)
        return str_replace(
            ['"/storage/', '(/storage/', '</storage/'],
            ['"'.$base.'/storage/', '('.$base.'/storage/', '<'.$base.'/storage/'],
            $text,
        );
    }

    /**
     * 从任意形态里提取图片引用的**存储态路径**列表（扫描 / 统计引用用）
     *
     * 支持：单值、JSON 数组、HTML/Markdown 正文。
     *
     * @param  array<int, string>|string|null  $value
     * @return array<int, string>
     */
    public static function extractPaths(array|string|null $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $paths = [];

        foreach ((array) $value as $item) {
            if (! is_string($item) || $item === '') {
                continue;
            }

            if (str_contains($item, '/storage/')) {
                preg_match_all('#/storage/([^"\'\s)\]]+)#i', $item, $m);
                foreach ($m[1] as $found) {
                    $paths[] = self::cleanExtracted('/storage/'.$found);
                }

                continue;
            }

            // 单值形态：先确认它「像一张本站图片」再收，避免把普通文案当路径登记
            if (self::isAssetCandidate($item)) {
                $paths[] = self::cleanExtracted($item);
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /** 提取结果里的零散字符清理 + 归一化到存储态 */
    private static function cleanExtracted(string $raw): string
    {
        $clean = trim($raw, "\"'\\.,;：，。)]} ");

        return self::toPath($clean) ?? '';
    }

    /**
     * 列的原始库值 → 可被 {@see self::extractPaths()} 消化的形态
     *
     * JSON 列在库里是字符串（'["uploads\/x.png"]'），必须先解成数组再交给抽取器，
     * 否则整串 JSON 会被当成"一个路径"，连「是否像图片」的候选判定都过不了。
     */
    public static function normalizeRaw(mixed $raw): array|string|null
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($trim, true);

            return is_array($decoded) ? $decoded : $trim;
        }

        return $raw;
    }

    /** 是否「本机 disk 管不到」的资源（需原样透传） */
    public static function isForeign(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (bool) preg_match('#^(?:https?:)?//#i', $value)
            || Str::startsWith($value, 'data:');
    }

    /** 是否指向本站 public disk（含历史上写死的任意 host） */
    public static function isOwnAsset(string $value): bool
    {
        return str_contains($value, '/storage/');
    }

    /**
     * 是否「像一张本站图片」—— 拼 URL 前的必要条件
     *
     * 只有三种形态会被当成资源：`/storage/...`（根相对）、`uploads/...`（存储态）、`storage/...`。
     * 其它任何字符串（普通文案、图标名、外链）都不足以据此拼 URL。
     */
    public static function isAssetCandidate(string $value): bool
    {
        $trimmed = ltrim($value, '/');

        return str_contains($value, '/storage/')
            || str_starts_with($trimmed, 'uploads/')
            || str_starts_with($trimmed, 'storage/');
    }

    /**
     * 剥掉资源前缀，得到存储态相对路径
     *
     * `http://h/storage/uploads/x.png` 与 `/storage/uploads/x.png` 都得 `uploads/x.png`
     */
    public static function stripAssetPrefix(string $value): string
    {
        $prefix = self::assetPrefix();
        $after = $value;

        if ($prefix !== '' && ($pos = strpos($value, $prefix)) !== false) {
            $after = substr($value, $pos + strlen($prefix));
        } elseif (($pos = strpos($value, '/storage/')) !== false) {
            $after = substr($value, $pos + strlen('/storage/'));
        }

        return ltrim($after, '/');
    }

    /** 任意形态 → disk 根目录起的相对路径（不含 `/storage/` 前缀） */
    public static function toRelative(string $value): string
    {
        return self::stripAssetPrefix($value);
    }

    /** public disk 的资源前缀，形如 `/storage` */
    public static function assetPrefix(): string
    {
        $prefix = parse_url(Storage::disk(self::DISK)->url(''), PHP_URL_PATH);

        return is_string($prefix) ? rtrim($prefix, '/') : '/storage';
    }
}
