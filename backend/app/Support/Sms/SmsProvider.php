<?php

namespace App\Support\Sms;

/**
 * 短信服务商字典（唯一来源）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D8（二期腾讯云预留）
 *
 * 新增服务商时三处同改：本类、`SmsChannelFactory` 分支、迁移里 `sms_configs` 的种子行。
 * 二期腾讯云只需加适配器 + 工厂分支 + 把 `tencent` 移入 {@see self::ENABLED}，业务层零改动。
 */
final class SmsProvider
{
    /** Mock：不发真短信，只留痕（开发/测试默认渠道） */
    public const MOCK = 'mock';

    /** 阿里云短信（第一期落地） */
    public const ALIYUN = 'aliyun';

    /** 腾讯云短信（二期） */
    public const TENCENT = 'tencent';

    /** 全部合法 provider（用于校验与后台展示） */
    public const ALL = [
        self::MOCK => 'Mock 渠道（不发真短信）',
        self::ALIYUN => '阿里云短信',
        self::TENCENT => '腾讯云短信',
    ];

    /** 已落地的 provider（腾讯云未排期，后台灰置「二期」） */
    public const ENABLED = [
        self::MOCK,
        self::ALIYUN,
    ];

    public static function isValid(string $provider): bool
    {
        return array_key_exists($provider, self::ALL);
    }

    /** 本期是否已支持（腾讯云返回 false → 后台提示二期支持） */
    public static function isAvailable(string $provider): bool
    {
        return in_array($provider, self::ENABLED, true);
    }

    public static function label(string $provider): string
    {
        return self::ALL[$provider] ?? $provider;
    }
}
