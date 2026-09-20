<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * 区块注册表（CMS-203）
 *
 * 「区块类型 → 字段 schema」的**唯一真源**，与 `CmsPageTemplate` 并列：
 * - 后台按它渲染区块选择器与每块的字段表单（复用 PageFieldForm）；
 * - 前台按它把区块分发给 `web/src/views/blocks/Block{Key}.vue`。
 *
 * 约定（与模板同一套体例）：
 * - 区块 key（如 `hero`）必须与前台组件 `web/src/views/blocks/Block{Hero}.vue` 一一对应，
 *   由 `CmsBlockTest` 的守卫用例强制校验；
 * - 字段 `type` 必须在 `CmsField::TYPES` 白名单内；
 * - 区块值以 JSON 存于 `cs_faq_article.blocks`，形如
 *   `[['type' => 'hero', 'data' => [...]], ...]`（数组顺序即前台渲染顺序）。
 *
 * 为什么用有序数组而不是 `{hero:{...}, gallery:{...}}` 这种按类型分组的对象：
 * 同一页面可以有**多个同类型区块**（两段图文、三个图集），且顺序即语义，数组才表达得了。
 */
final class CmsBlock
{
    /** 单页区块数量上限（防误操作塞进几百块把页面拖垮） */
    public const MAX_BLOCKS = 30;

    /** 区块定义（数组顺序即后台「添加区块」下拉顺序） */
    public const BLOCKS = [
        'hero' => [
            'label' => '首屏横幅',
            'description' => '页面顶部的大图区块，用于品牌或活动主张',
            'fields' => [
                ['key' => 'title', 'label' => '主标题', 'type' => 'text', 'required' => true],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'textarea'],
                ['key' => 'image', 'label' => '背景图', 'type' => 'image', 'hint' => '建议 1920×560；留空则用纯色底'],
                ['key' => 'button_text', 'label' => '按钮文案', 'type' => 'text', 'hint' => '留空则不显示按钮'],
                ['key' => 'button_link', 'label' => '按钮链接', 'type' => 'text', 'hint' => '如 /p/contact 或 https://…'],
            ],
        ],
        'text_image' => [
            'label' => '图文分栏',
            'description' => '左图右文（可反转），适合介绍一段业务或故事',
            'fields' => [
                ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                ['key' => 'text', 'label' => '正文', 'type' => 'markdown', 'required' => true],
                ['key' => 'image', 'label' => '配图', 'type' => 'image'],
                [
                    'key' => 'side', 'label' => '图片位置', 'type' => 'select',
                    'default' => 'left',
                    'options' => [
                        ['value' => 'left', 'label' => '图片在左'],
                        ['value' => 'right', 'label' => '图片在右'],
                    ],
                ],
            ],
        ],
        'gallery' => [
            'label' => '图集',
            'description' => '一组图片的网格展示',
            'fields' => [
                ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                ['key' => 'images', 'label' => '图片', 'type' => 'image_list', 'required' => true],
            ],
        ],
        'rich_text' => [
            'label' => '富文本',
            'description' => '一段纯文字内容（支持 Markdown）',
            'fields' => [
                ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                ['key' => 'body', 'label' => '正文', 'type' => 'markdown', 'required' => true],
            ],
        ],
        'faq_embed' => [
            'label' => '帮助中心嵌入',
            'description' => '把某个帮助栏目下的已发布文章以列表形式嵌进页面',
            'fields' => [
                ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                ['key' => 'category_id', 'label' => '选取栏目', 'type' => 'channels', 'hint' => '只列出「栏目」类型，不含单页'],
                [
                    'key' => 'limit', 'label' => '显示条数', 'type' => 'select',
                    'default' => '5',
                    'options' => [
                        ['value' => '3', 'label' => '3 条'],
                        ['value' => '5', 'label' => '5 条'],
                        ['value' => '8', 'label' => '8 条'],
                    ],
                ],
            ],
        ],
    ];

    /** 全部区块 key */
    public static function blockKeys(): array
    {
        return array_keys(self::BLOCKS);
    }

    /** 区块是否存在 */
    public static function exists(string $key): bool
    {
        return isset(self::BLOCKS[$key]);
    }

    /** 区块的字段 schema；未知区块返回空数组 */
    public static function schema(string $key): array
    {
        return self::BLOCKS[$key]['fields'] ?? [];
    }

    /** key → 标签（供后台下拉） */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::BLOCKS as $key => $block) {
            $labels[$key] = $block['label'];
        }

        return $labels;
    }

    /**
     * 供后台渲染区块选择器的元数据
     *
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::BLOCKS as $key => $block) {
            $options[] = [
                'key' => $key,
                'label' => $block['label'],
                'description' => $block['description'] ?? '',
                'fields' => $block['fields'],
            ];
        }

        return $options;
    }

    /** 前台组件名（web/src/views/blocks/ 下），供守卫测试校验一一对应 */
    public static function componentName(string $key): string
    {
        return 'Block'.str_replace(' ', '', ucwords(str_replace('_', ' ', $key))).'.vue';
    }

    /** 新区块的默认数据（按 schema 生成） */
    public static function defaultsOf(string $key): array
    {
        return CmsField::defaultsOf(self::schema($key));
    }

    /**
     * 归一化区块数组：丢弃未知类型与结构不合法的项，逐块按 schema 过滤数据
     *
     * 与字段的「未知键静默丢弃」同口径 —— 脏数据天然进不来，且不会因为一个坏块
     * 就让整页保存失败。超量直接截断到 MAX_BLOCKS。
     *
     * @param  mixed  $blocks
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function filterPayload(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $result = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if (! self::exists($type)) {
                continue;
            }

            $data = $block['data'] ?? [];
            if (! is_array($data)) {
                $data = [];
            }

            $result[] = [
                'type' => $type,
                'data' => CmsField::filterPayload(self::schema($type), $data),
            ];

            if (count($result) >= self::MAX_BLOCKS) {
                break;
            }
        }

        return $result;
    }

    /**
     * 逐块校验规则
     *
     * 按**实际入参**逐块展开（而不是一条通配规则），好处是报错能直接指出
     * 「第 2 块的 type 不合法」。内容字段的规则来自各块自己的 schema。
     *
     * `type` 用 Rule::in 而不是「不合法就静默丢弃」：类型属于结构而非内容，
     * 传了未知类型说明客户端与后端真源不同步，应当报错而不是悄悄吃掉一块内容
     * （内容层面的字段仍按 schema 静默过滤，见 filterPayload）。
     *
     * @param  array<int, array<string, mixed>>|null  $blocks
     * @return array<string, list<mixed>>
     */
    public static function indexedRules(?array $blocks): array
    {
        $rules = [
            'blocks' => ['nullable', 'array', 'max:'.self::MAX_BLOCKS],
        ];

        foreach (array_values($blocks ?? []) as $i => $block) {
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';

            $rules["blocks.{$i}"] = ['array'];
            $rules["blocks.{$i}.type"] = ['required', 'string', Rule::in(self::blockKeys())];
            $rules["blocks.{$i}.data"] = ['nullable', 'array'];

            if (! self::exists($type)) {
                continue;
            }

            foreach (CmsField::rules(self::schema($type), "blocks.{$i}.data") as $fieldPath => $fieldRules) {
                $rules[$fieldPath] = $fieldRules;
            }
        }

        return $rules;
    }
}
