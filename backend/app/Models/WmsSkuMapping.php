<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WMS SKU 映射（WMS 计划 P0 / F6）
 *
 * 平台 SKU ↔ WMS 货品编码的翻译，按仓库隔离。
 * 仅 `sku_mapping_mode=manual` 时生效；`same` 模式直接回落平台 sku_code。
 */
class WmsSkuMapping extends Model
{
    protected $fillable = [
        'warehouse_id', 'sku_id', 'platform_sku_code', 'wms_sku_code', 'barcode', 'status',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'sku_id' => 'integer',
        'status' => 'integer',
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
