<?php

namespace App\Support;

/**
 * 弱口令检测（SEC-05）
 *
 * Laravel 内置的 `Password::uncompromised()` 需要联网查询 Have I Been Pwned，
 * 对内网/离线部署不可用，因此这里改为**本地黑名单 + 模式检测**：
 *
 * 1. 常见弱口令黑名单（Top-N，覆盖撞库字典里命中率最高的那批）；
 * 2. 纯重复字符（aaaaaa / 111111）；
 * 3. 连续递增/递减数字与字母（123456 / abcdef / 654321）；
 * 4. 键盘相邻走位（qwerty / 1qaz2wsx 这类模式做近似匹配）。
 *
 * 需要说明的是：本地黑名单**无法替代** `uncompromised()` 的泄露库比对能力。
 * 若部署环境允许出网，建议在此基础上叠加 `Password::uncompromised()`。
 */
final class WeakPassword
{
    /** 常见弱口令（小写比对，命中即拒绝） */
    private const BLACKLIST = [
        '123456', '1234567', '12345678', '123456789', '1234567890',
        '111111', '000000', '222222', '666666', '888888',
        'password', 'password1', 'password123', 'passwd', 'passw0rd',
        'qwerty', 'qwertyuiop', 'qwerty123', 'qwe123', 'qazwsx', '1qaz2wsx',
        'abc123', 'abc123456', 'a123456', 'a12345678', 'aa123456',
        'admin', 'admin123', 'administrator', 'root', 'root123', 'toor',
        'iloveyou', 'letmein', 'welcome', 'monkey', 'dragon', 'sunshine',
        'princess', 'football', 'baseball', 'master', 'shadow', 'superman',
        'trustno1', 'zxcvbn', 'asdfgh', 'asdf1234', '1q2w3e4r',
        'woaini', 'woaini1314', '5201314', '1314520', 'wang', 'zhang',
        'taobao', 'tianmao', 'jingdong', 'alipay', 'weixin', 'qq123456',
        'cubeshop', 'cubeshop123', 'shop123', 'test123', 'test1234', 'demo123',
    ];

    /** 键盘相邻行（用于检测走位模式） */
    private const KEYBOARD_ROWS = [
        '1234567890',
        'qwertyuiop',
        'asdfghjkl',
        'zxcvbnm',
    ];

    /**
     * 判断是否为弱口令
     */
    public static function isWeak(string $password): bool
    {
        $lower = strtolower($password);

        // 1. 黑名单
        if (in_array($lower, self::BLACKLIST, true)) {
            return true;
        }

        // 2. 纯重复字符（长度 >= 6 时视为弱）
        if (strlen($lower) >= 6 && count(array_unique(str_split($lower))) === 1) {
            return true;
        }

        // 3. 连续递增/递减（数字或字母，长度 >= 6）
        if (strlen($lower) >= 6 && (self::isSequential($lower) || self::isSequential(strrev($lower)))) {
            return true;
        }

        // 4. 键盘走位：取自某一行且连续（长度 >= 6）
        if (strlen($lower) >= 6 && self::isKeyboardWalk($lower)) {
            return true;
        }

        return false;
    }

    /**
     * 供 Validator 使用的闭包规则（挂在 password 字段上）
     *
     * @return callable(string, mixed, \Closure): void
     */
    public static function rule(): callable
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && self::isWeak($value)) {
                $fail('密码过于简单或过于常见，请更换');
            }
        };
    }

    /**
     * 是否字符逐个 +1 递增（如 abcdef / 123456）
     */
    private static function isSequential(string $s): bool
    {
        $chars = str_split($s);
        for ($i = 1; $i < count($chars); $i++) {
            if (ord($chars[$i]) - ord($chars[$i - 1]) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否为键盘相邻键位的连续走位（含反方向）
     */
    private static function isKeyboardWalk(string $lower): bool
    {
        foreach (self::KEYBOARD_ROWS as $row) {
            foreach ([$row, strrev($row)] as $seq) {
                if (strlen($lower) >= 6 && str_contains($seq, $lower)) {
                    return true;
                }
            }
        }

        return false;
    }
}
