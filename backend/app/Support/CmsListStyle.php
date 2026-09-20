<?php

namespace App\Support;

/**
 * 栏目列表形态（CMS 新闻中心，一期）
 *
 * 真源：后台「列表形态」下拉与前台新闻中心渲染都从此取，两侧共用一份契约
 * （体例对齐 App\Support\ConfigGroup / App\Support\CmsPageTemplate 的真源模式）。
 *
 * 仅 `type=channel` 的栏目有意义：
 * - `card`：图文卡片（封面图 + 标题 + 摘要，适合「图文新闻」）
 * - `list`：列表行（标题 + 日期紧凑行，适合「列表新闻」）
 *
 * ⚠️ 不复用 `template` 列（`template` 是单页模板语义），两者混用会让两个概念互相污染。
 */
final class CmsListStyle
{
    public const CARD = 'card';
    public const LIST = 'list';

    /** 中文标签（下拉展示） */
    public const LABELS = [
        self::CARD => '图文卡片',
        self::LIST => '列表行',
    ];

    /** 默认值（新建栏目时回填，避免 NULL 分叉） */
    public const DEFAULT = self::LIST;

    /** 全部合法值（校验用） */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $value): bool
    {
        return isset(self::LABELS[$value]);
    }

    public static function label(string $value): string
    {
        return self::LABELS[$value] ?? self::LABELS[self::DEFAULT];
    }
}
