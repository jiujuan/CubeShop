<?php

namespace App\Support;

/**
 * 系统配置分组真源（后台「系统配置」按 Tab 管理）
 *
 * 分组由 config_key 的「前缀」推导（如 `site.name` → 站点信息），
 * 因此新增配置项只要沿用既有前缀即自动归入对应 Tab，无需改表或改前端。
 * 前缀 → 标签的唯一映射在此定义；不同前缀可映射到同一标签以合并为一个 Tab。
 *
 * 约定：新增 `xxx.*` 配置时，必须同时在此登记前缀，否则会落到「其它设置」。
 */
final class ConfigGroup
{
    /**
     * 前缀 → 分组标签（数组顺序即 Tab 顺序）
     *
     * @var array<string, string>
     */
    public const PREFIX_LABELS = [
        'site' => '站点信息',
        'auth' => '认证安全',
        'payment' => '支付与充值',
        'order' => '订单交易',
        'inventory' => '库存与预警',
        'review' => '评价与通知',
        'notify' => '评价与通知',
        'shipping' => '物流配送',
        'waybill' => '物流配送',
        'search' => '搜索与推荐',
        'sms' => '消息通知',
    ];

    /** 未登记前缀的兜底分组 */
    public const DEFAULT_LABEL = '其它设置';

    /** 取配置键的分组标签 */
    public static function labelOf(string $configKey): string
    {
        $prefix = str_contains($configKey, '.')
            ? substr($configKey, 0, strpos($configKey, '.'))
            : $configKey;

        return self::PREFIX_LABELS[$prefix] ?? self::DEFAULT_LABEL;
    }

    /**
     * 全部 Tab 标签（按前缀声明顺序去重，兜底分组置末）
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        $labels = array_values(array_unique(array_values(self::PREFIX_LABELS)));
        $labels[] = self::DEFAULT_LABEL;

        return $labels;
    }

    /** 标签排序权重（未知标签排最后，保持稳定） */
    public static function orderOf(string $label): int
    {
        $index = array_search($label, self::labels(), true);

        return $index === false ? PHP_INT_MAX : (int) $index;
    }
}
