<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 库存盘点单
 *
 * 状态机：draft（已生成明细与账面快照）→ counting（已有实盘录入）→ posted（已过账，终态）
 *         draft/counting → cancelled（作废，终态）
 *
 * ⚠️ 与 wms_inventory_diffs 的区别：那里是「仓库系统 vs 平台」的对账差异，
 *    这里是「人工实物点数 vs 平台账面」的校准，两者数据源与处理方式都不同。
 */
class InventoryCheck extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_COUNTING = 'counting';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_ALL = 'all';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_BRAND = 'brand';

    public const SCOPE_KEYWORD = 'keyword';

    public const SCOPE_CUSTOM = 'custom';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '待盘点',
        self::STATUS_COUNTING => '盘点中',
        self::STATUS_POSTED => '已过账',
        self::STATUS_CANCELLED => '已作废',
    ];

    public const SCOPE_LABELS = [
        self::SCOPE_ALL => '全部商品',
        self::SCOPE_CATEGORY => '按分类',
        self::SCOPE_BRAND => '按品牌',
        self::SCOPE_KEYWORD => '按关键词',
        self::SCOPE_CUSTOM => '自定义清单',
    ];

    protected $table = 'inventory_checks';

    protected $fillable = [
        'check_no', 'title', 'scope_type', 'scope_value', 'status',
        'item_count', 'counted_count', 'diff_count', 'total_diff_qty', 'remark',
        'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'item_count' => 'integer',
        'counted_count' => 'integer',
        'diff_count' => 'integer',
        'total_diff_qty' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCheckItem::class, 'check_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_COUNTING], true);
    }
}
