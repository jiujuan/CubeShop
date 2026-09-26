<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 库存盘点明细
 *
 * system_qty / locked_qty 是**开单时**的账面快照，只用于界面展示；
 * 过账时以「过账时刻的实时库存」计算差异并写入 diff_qty——
 * 开单到过账可能跨数天，期间正常出入库不能被盘点冲掉。
 */
class InventoryCheckItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COUNTED = 'counted';

    public const STATUS_POSTED = 'posted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待盘点',
        self::STATUS_COUNTED => '已盘点',
        self::STATUS_POSTED => '已过账',
        self::STATUS_SKIPPED => '已跳过',
    ];

    protected $table = 'inventory_check_items';

    protected $fillable = [
        'check_id', 'sku_id', 'sku_code', 'product_title', 'specs_text',
        'system_qty', 'locked_qty', 'counted_qty', 'diff_qty', 'status', 'remark',
    ];

    protected $casts = [
        'system_qty' => 'integer',
        'locked_qty' => 'integer',
        'counted_qty' => 'integer',
        'diff_qty' => 'integer',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(InventoryCheck::class, 'check_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }
}
