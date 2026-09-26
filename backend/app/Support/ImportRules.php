<?php

namespace App\Support;

/**
 * 批量导入通用规则（表头、行数上限、模式）
 *
 * 与 {@see \App\Support\ShippingRules} 同体例：把「模板契约」常量收在一处，
 * 控制器只做读文件 + 调服务，表头/上限的修改不需要碰业务代码。
 */
class ImportRules
{
    public const MODE_CREATE = 'create';

    public const MODE_UPDATE = 'update';

    /** @var array<int, string> */
    public const MODES = [self::MODE_CREATE, self::MODE_UPDATE];

    /**
     * 商品导入模板表头（列序固定，严格比对）
     *
     * create 模式全部列生效；update 模式只认「SKU编码 / 销售价 / 库存 / SKU状态」四列。
     */
    public const PRODUCT_HEADERS = [
        '商品编码',
        '商品标题',
        '副标题',
        '分类',
        '品牌',
        '主图',
        '详情',
        '商品状态',
        '重量(g)',
        '排序',
        'SKU编码',
        '规格',
        '销售价',
        '库存',
        'SKU状态',
    ];

    /** 新建模式单次行数上限（建商品 + SKU + 库存，成本高） */
    public const CREATE_MAX_ROWS = 300;

    /** 更新模式单次行数上限（只改价格/库存，成本低） */
    public const UPDATE_MAX_ROWS = 1000;

    public static function maxRows(string $mode): int
    {
        return $mode === self::MODE_UPDATE ? self::UPDATE_MAX_ROWS : self::CREATE_MAX_ROWS;
    }

    /**
     * 前端展示用的醒目上限提示
     *
     * 上限是硬约束（超限整批拒绝），必须在上传前就让人看见，
     * 而不是等传到一半才报错——故文案在这里统一定义，前后端共用同一份措辞。
     */
    public static function limitNote(): string
    {
        return sprintf(
            '单次导入行数上限：新建商品 %d 行、更新价格/库存 %d 行，超出请拆分文件后分次导入',
            self::CREATE_MAX_ROWS,
            self::UPDATE_MAX_ROWS,
        );
    }
}
