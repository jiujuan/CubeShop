<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * CMS 字段工具（CMS-203 抽出）
 *
 * 一期把「字段 schema → 默认值 / 过滤入参 / 校验规则」这套逻辑写在 `CmsPageTemplate`
 * 里；二期引入区块后出现了**第二类需要同一套逻辑的真源**（`CmsBlock`），
 * 于是把它抽到这里，两处共用一份实现 —— 避免复制出第二份会各自漂移的校验。
 *
 * 字段结构：['key' => string, 'label' => string, 'type' => string,
 *           'required' => bool?, 'default' => mixed?, 'hint' => string?,
 *           'options' => array?,   // select 使用：[['value'=>..,'label'=>..]]
 *           'item' => array?]      // repeater 使用，描述子字段
 */
final class CmsField
{
    /** 字段类型白名单 */
    public const TYPES = ['text', 'textarea', 'markdown', 'image', 'image_list', 'repeater', 'select', 'channels'];

    /** 数组型字段类型（空值为 [] 而非 ''） */
    public const ARRAY_TYPES = ['repeater', 'image_list'];

    /** 字段类型的空值：数组型给 []，其余给 '' */
    public static function emptyValueOf(string $type): mixed
    {
        return in_array($type, self::ARRAY_TYPES, true) ? [] : '';
    }

    /**
     * 表单初值：按 schema 生成「字段 key → 空值/default」
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function defaultsOf(array $fields): array
    {
        $defaults = [];
        foreach ($fields as $field) {
            $defaults[$field['key']] = $field['default'] ?? self::emptyValueOf($field['type']);
        }

        return $defaults;
    }

    /**
     * 过滤入参：只保留 schema 内的键（未知键静默丢弃），缺失键补默认值，null 归一为空值
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function filterPayload(array $fields, array $payload): array
    {
        $result = [];
        foreach (self::defaultsOf($fields) as $fieldKey => $default) {
            $value = $payload[$fieldKey] ?? $default;
            $result[$fieldKey] = $value === null ? $default : $value;
        }

        return $result;
    }

    /**
     * 字段级校验规则
     *
     * ⚠️ 非必填字段用 `nullable` 而非 `present`：Laravel 的 ConvertEmptyStringsToNull
     * 会把 '' 转成 null，这正好让「清空某字段」合法通过（与系统配置的教训一致）。
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  string  $prefix  规则键前缀（单页为 `fields`，区块为 `blocks.0.data`）
     * @return array<string, list<mixed>>
     */
    public static function rules(array $fields, string $prefix): array
    {
        $rules = [];
        foreach ($fields as $field) {
            $fieldKey = $prefix.'.'.$field['key'];
            $required = (bool) ($field['required'] ?? false);

            $fieldRules = [$required ? 'required' : 'nullable'];

            if (in_array($field['type'], self::ARRAY_TYPES, true)) {
                $fieldRules[] = 'array';
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = match ($field['type']) {
                    'text', 'image', 'select' => 'max:500',
                    'textarea' => 'max:5000',
                    default => 'max:200000', // markdown 正文
                };
            }

            if ($field['type'] === 'select') {
                $fieldRules[] = Rule::in(array_column($field['options'] ?? [], 'value'));
            }

            $rules[$fieldKey] = $fieldRules;
        }

        return $rules;
    }

    /**
     * 断言 schema 自身合法（类型在白名单内、select 必须给出 options）
     *
     * 供守卫测试调用：真源里写错一个 type 就该在测试里炸掉，而不是等线上表单渲染不出来。
     *
     * @param  array<string, list<array<string, mixed>>>  $schemas  label => fields
     * @return list<string> 问题描述，空数组表示全部合法
     */
    public static function inspect(array $schemas): array
    {
        $problems = [];

        foreach ($schemas as $label => $fields) {
            foreach ($fields as $field) {
                $key = $field['key'] ?? '(缺 key)';
                $type = $field['type'] ?? '';

                if (! in_array($type, self::TYPES, true)) {
                    $problems[] = "{$label}.{$key}: 未知字段类型「{$type}」";
                    continue;
                }

                if ($type === 'select' && empty($field['options'])) {
                    $problems[] = "{$label}.{$key}: select 必须提供 options";
                }

                foreach ($field['item'] ?? [] as $sub) {
                    if (! in_array($sub['type'] ?? '', self::TYPES, true)) {
                        $problems[] = "{$label}.{$key}.{$sub['key']}: 未知子字段类型「{$sub['type']}」";
                    }
                }
            }
        }

        return $problems;
    }
}
