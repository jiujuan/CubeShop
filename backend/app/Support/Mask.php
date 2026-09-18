<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 敏感字段脱敏（SEC-09：导出/日志中的 PII 不可明文）
 *
 * 仅保留前后若干位，中间打码。脱敏是**默认行为**——导出与日志统一调用本类，
 * 不允许在 CSV / 日志里直接拼接原始手机号、身份证、姓名等字段。
 */
final class Mask
{
    /**
     * 手机号脱敏：保留前 3 后 4，中间以 * 填充。
     * 例：13812345678 → 138****5678
     */
    public static function phone(?string $phone): ?string
    {
        if (blank($phone)) {
            return $phone;
        }

        $p = (string) $phone;

        if (mb_strlen($p) <= 7) {
            return mb_substr($p, 0, 1).str_repeat('*', mb_strlen($p) - 1);
        }

        return mb_substr($p, 0, 3).str_repeat('*', mb_strlen($p) - 7).mb_substr($p, -4);
    }

    /**
     * 姓名脱敏：中文保留姓氏、其余打码；英文保留首字母。
     * 例：张三 → 张*；Alice → A***
     */
    public static function name(?string $name): ?string
    {
        if (blank($name)) {
            return $name;
        }

        $n = (string) $name;

        if (mb_strlen($n) <= 1) {
            return $n;
        }

        if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $n)) {
            return mb_substr($n, 0, 1).str_repeat('*', mb_strlen($n) - 1);
        }

        return mb_substr($n, 0, 1).str_repeat('*', mb_strlen($n) - 1);
    }

    /**
     * 邮箱脱敏：保留首段首字符与域名。
     * 例：alice@example.com → a***@example.com
     */
    public static function email(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (mb_strlen($local) <= 1) {
            return '*@'.$domain;
        }

        return mb_substr($local, 0, 1).str_repeat('*', mb_strlen($local) - 1).'@'.$domain;
    }
}
