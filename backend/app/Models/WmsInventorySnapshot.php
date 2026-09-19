<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WMS 库存快照（WMS 计划 P5 / F2）
 *
 * 一个仓库一个 SKU 只保留最新一行（UNIQUE(warehouse_id, sku_id)），
 * 重复同步 upsert 覆盖。本表**不是**库存真源——平台可售库存仍在 `inventories`。
 */
class WmsInventorySnapshot extends Model
{
    protected $fillable = [
        'warehouse_id', 'sku_id', 'wms_sku_code',
        'available_qty', 'locked_qty', 'synced_at',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'sku_id' => 'integer',
        'available_qty' => 'integer',
        'locked_qty' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }
}
