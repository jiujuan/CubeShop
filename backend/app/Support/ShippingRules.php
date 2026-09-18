<?php

namespace App\Support;

/**
 * 物流规则常量（V1.1 T-043 / T-044，集中定义）
 */
class ShippingRules
{
    /** 运单号：8~32 位，仅字母数字与短横线 */
    public const TRACKING_NO_REGEX = '/^[A-Za-z0-9-]{8,32}$/';

    public const TRACKING_NO_MIN = 8;

    public const TRACKING_NO_MAX = 32;

    /** 批量发货单次文件行数上限（T-044） */
    public const BATCH_SHIP_MAX_ROWS = 500;

    /** 批量发货 Excel 表头（列序固定） */
    public const BATCH_SHIP_HEADERS = ['订单号', '快递公司编码', '运单号'];
}
