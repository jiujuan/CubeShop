<?php

namespace App\Support;

/**
 * SKU 映射方式（WMS 计划 P0 / F6）
 *
 * - SAME：不查映射表，直接回落平台 `product_skus.sku_code`（默认，零配置可用）
 * - MANUAL：必须在 `wms_sku_mappings` 命中且启用，否则拒绝推送（抛 40009）
 */
final class WmsMappingMode
{
    public const SAME = 'same';

    public const MANUAL = 'manual';

    public const ALL = [
        self::SAME => '跟随平台 SKU 编码',
        self::MANUAL => '手工映射',
    ];

    public static function isValid(string $mode): bool
    {
        return array_key_exists($mode, self::ALL);
    }
}
