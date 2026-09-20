<?php

namespace App\Support;

/**
 * 单页模板注册表（内容中心 CMS）
 *
 * 这是「单页模板 → 字段 schema」的**唯一真源**：后台按它动态生成表单，前台按它
 * 渲染对应 Vue 模板组件，两侧共用一份契约（体例对齐 App\Support\ConfigGroup /
 * App\Support\AdminRole 的真源模式）。
 *
 * 约定：
 * - 模板 key（如 `about`）必须与前台组件 `web/src/views/pages/Page{Key}.vue` 一一对应，
 *   由 CmsPageTemplateTest 的守卫用例强制校验 —— 新增模板漏写前台组件会直接测试失败。
 * - 字段 `type` 必须在本类 FIELD_TYPES 白名单内，未知类型会被守卫用例拦截。
 * - 单页字段值以 JSON 存于 cs_faq_article.page_fields，键即下面的 field `key`。
 *
 * 字段结构：['key' => string, 'label' => string, 'type' => string,
 *           'required' => bool?, 'default' => mixed?, 'hint' => string?,
 *           'item' => array?]  // item 仅 repeater 使用，描述子字段
 */
final class CmsPageTemplate
{
    /**
     * 字段类型白名单（真源在 CmsField，被区块真源共用；此处保留别名避免一期调用点改动）
     *
     * @deprecated 新代码请用 CmsField::TYPES
     */
    public const FIELD_TYPES = CmsField::TYPES;

    /** 数组型字段类型（空值为 [] 而非 ''） */
    public const ARRAY_TYPES = CmsField::ARRAY_TYPES;

    /**
     * 区块化模板的 key
     *
     * 它不是一个「固定版式」，而是「内容由 blocks 数组自由编排」的标记：
     * 后台渲染区块编辑器（PageBlockEditor），前台由 PageBlocks.vue 分发到区块组件。
     * 仍然遵守「模板 key ↔ Page{Key}.vue 一一对应」的契约，所以守卫测试天然继续成立。
     */
    public const TEMPLATE_BLOCKS = 'blocks';

    /** 模板定义（数组顺序即后台模板下拉顺序） */
    public const TEMPLATES = [
        'about' => [
            'label' => '关于我们',
            'fields' => [
                [
                    'key' => 'banner', 'label' => '顶部横幅图', 'type' => 'image',
                    'hint' => '建议 1920×420；留空则不显示横幅区',
                ],
                [
                    'key' => 'intro', 'label' => '公司简介', 'type' => 'markdown',
                    'required' => true,
                ],
                [
                    'key' => 'milestones', 'label' => '发展历程', 'type' => 'repeater',
                    'item' => [
                        ['key' => 'year', 'label' => '年份', 'type' => 'text'],
                        ['key' => 'event', 'label' => '事件', 'type' => 'text'],
                    ],
                ],
                [
                    'key' => 'values', 'label' => '企业价值观', 'type' => 'repeater',
                    'item' => [
                        ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                        ['key' => 'desc', 'label' => '说明', 'type' => 'textarea'],
                    ],
                ],
            ],
        ],
        'contact' => [
            'label' => '联系我们',
            'fields' => [
                ['key' => 'address', 'label' => '公司地址', 'type' => 'text', 'required' => true],
                ['key' => 'phone', 'label' => '联系电话', 'type' => 'text'],
                ['key' => 'email', 'label' => '客服邮箱', 'type' => 'text'],
                ['key' => 'work_time', 'label' => '工作时间', 'type' => 'text'],
                ['key' => 'map_image', 'label' => '地图图片', 'type' => 'image'],
                ['key' => 'intro', 'label' => '补充说明', 'type' => 'markdown'],
            ],
        ],
        // 区块化单页：字段为空（内容走 blocks 列），但模板 key 仍是合法模板
        self::TEMPLATE_BLOCKS => [
            'label' => '自由区块',
            'fields' => [],
        ],
    ];

    /** 是否为区块化模板 */
    public static function isBlocks(string $key): bool
    {
        return $key === self::TEMPLATE_BLOCKS;
    }

    /** 全部模板 key */
    public static function templateKeys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /** 模板是否存在 */
    public static function exists(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    /** key → 模板标签 */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::TEMPLATES as $key => $template) {
            $labels[$key] = $template['label'];
        }

        return $labels;
    }

    /** 模板的字段 schema；未知模板返回空数组 */
    public static function schema(string $key): array
    {
        return self::TEMPLATES[$key]['fields'] ?? [];
    }

    /** 前台组件名（web/src/views/pages/ 下），供守卫测试校验一一对应 */
    public static function componentName(string $key): string
    {
        return 'Page'.ucfirst($key).'.vue';
    }

    /**
     * 表单初值：按 schema 生成「字段 key → 空值/default」
     *
     * @return array<string, mixed>
     */
    public static function defaultsOf(string $key): array
    {
        return CmsField::defaultsOf(self::schema($key));
    }

    /** 字段类型的空值：数组型给 []，其余给 '' */
    public static function emptyValueOf(string $type): mixed
    {
        return CmsField::emptyValueOf($type);
    }

    /**
     * 过滤入参：只保留 schema 内的键（未知键静默丢弃），缺失键补默认值，null 归一为空值
     *
     * 供保存单页时调用 —— 脏数据天然进不来。
     *
     * @return array<string, mixed>
     */
    public static function filterPayload(string $key, array $payload): array
    {
        return CmsField::filterPayload(self::schema($key), $payload);
    }

    /**
     * 字段级校验规则（保存单页时用）
     *
     * ⚠️ 非必填字段用 `nullable` 而非 `present`：Laravel 的 ConvertEmptyStringsToNull
     * 会把 '' 转成 null，这正好让「清空某字段」合法通过（与系统配置的教训一致）。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $key): array
    {
        return CmsField::rules(self::schema($key), 'fields');
    }
}
