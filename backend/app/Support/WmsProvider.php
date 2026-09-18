<?php

namespace App\Support;

/**
 * WMS 服务商标识（唯一来源）
 *
 * 新增服务商时本类 + 迁移 provider 枚举 + `WmsAdapterFactory` 三处同改。
 */
final class WmsProvider
{
    /** 菜鸟（奇门仓配）——本期唯一落地的服务商 */
    public const CAINIAO = 'cainiao';

    /** 京东云仓——P8 启用，本期仅保留枚举与配置字段 */
    public const JD_CLOUD = 'jd_cloud';

    /** Mock（内部联调兜底，不落库为 provider 值，仅日志/工厂内部使用） */
    public const MOCK = 'mock';

    /** 全部合法 provider（用于校验与后台展示） */
    public const ALL = [
        self::CAINIAO => '菜鸟（奇门）',
        self::JD_CLOUD => '京东云仓',
    ];

    /** 已可用的 provider（京东未排期，保存即提示「敬请期待」） */
    public const ENABLED = [
        self::CAINIAO,
    ];

    public static function isValid(string $provider): bool
    {
        return array_key_exists($provider, self::ALL);
    }

    /** 本期是否已支持（京东返回 false → 后台提示 P8 支持） */
    public static function isAvailable(string $provider): bool
    {
        return in_array($provider, self::ENABLED, true);
    }

    public static function label(string $provider): string
    {
        return self::ALL[$provider] ?? $provider;
    }
}
