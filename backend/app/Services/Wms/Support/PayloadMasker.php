<?php

namespace App\Services\Wms\Support;

/**
 * 报文脱敏（WMS 计划 P2 / F8、Step 3）
 *
 * 唯一使命：让 `wms_api_logs` 里**永远搜不到** AppSecret / access_token / 完整手机号，
 * 同时保留足够的排障信息（单号、商品编码、数量、错误码原样保留）。
 *
 * 两条规则（递归应用于任意深度）：
 * 1. **整值抹除**：键名归一后含 `keywords` 任一项 → 值替换为 `***`；
 * 2. **保留尾部**：键名归一后含 `phone_keys` 任一项 → 手机号保留后 N 位（`*******8000`）。
 *
 * 键名归一 = 转小写 + 去掉 `_` `-` `.` 空格，因此 `app_secret` / `appSecret` /
 * `app.secret` 都能命中 `appsecret`。数组键（如 `items[0].mobile` 的中间层）
 * 会原样下钻，不参与匹配。
 *
 * **放在哪里生效**：统一在 {@see \App\Services\Wms\WmsApiLogService} 落库前调用，
 * 是「出站报文必脱敏」的唯一保证点——调用方忘记脱敏也不可能泄漏。
 */
final class PayloadMasker
{
    /** 整值抹除后的占位符 */
    public const REDACTED = '***';

    /** @var list<string> */
    private readonly array $keywords;

    /** @var list<string> */
    private readonly array $phoneKeys;

    private readonly int $phoneKeepTail;

    /**
     * @param  array{keywords?: list<string>, phone_keys?: list<string>, phone_keep_tail?: int}|null  $options
     *                                                                                                          不传则读 `config('wms.mask')`（测试可注入固定值）
     */
    public function __construct(?array $options = null)
    {
        $options ??= (array) config('wms.mask', []);

        $this->keywords = array_map($this->normalizeKey(...), (array) ($options['keywords'] ?? []));
        $this->phoneKeys = array_map($this->normalizeKey(...), (array) ($options['phone_keys'] ?? []));
        $this->phoneKeepTail = max(0, (int) ($options['phone_keep_tail'] ?? 4));
    }

    /**
     * 递归脱敏任意结构。返回新数组，不修改入参。
     */
    public function mask(mixed $payload): mixed
    {
        if (is_array($payload)) {
            $masked = [];
            foreach ($payload as $key => $value) {
                $masked[$key] = is_string($key)
                    ? $this->maskValueForKey($key, $value)
                    : $this->mask($value);   // 纯数字索引：继续下钻
            }

            return $masked;
        }

        return $payload;
    }

    /** 单个键的处置；命中抹除/尾部保留规则时不下钻 */
    private function maskValueForKey(string $key, mixed $value): mixed
    {
        $normalized = $this->normalizeKey($key);

        if ($this->containsAny($normalized, $this->keywords)) {
            return self::REDACTED;
        }

        if ($this->containsAny($normalized, $this->phoneKeys) && is_scalar($value)) {
            return $this->maskPhone((string) $value);
        }

        return $this->mask($value);
    }

    /** 手机号/联系方式：保留后 N 位，前面全星号（空值原样返回，避免出现 `***` 噪音） */
    public function maskPhone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= $this->phoneKeepTail) {
            // 太短：整串抹掉，避免「保留后 4 位」等于原样输出
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $this->phoneKeepTail).substr($value, -$this->phoneKeepTail);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['_', '-', '.', ' ', "\t"], '', $key));
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
