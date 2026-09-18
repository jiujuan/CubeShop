<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 仓库档案（WMS 计划 P0 / F1）
 *
 * WMS 配置、SKU 映射、履约出库单、退货入库单均以仓库为归属维度。
 * 本期先建仓 + 预置默认仓（WarehouseSeeder），不做商品/库存的仓库改造。
 */
class Warehouse extends Model
{
    protected $fillable = [
        'code', 'name', 'contact_name', 'contact_phone',
        'province', 'city', 'district', 'address', 'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    /** WMS 对接配置（一仓一条） */
    public function wmsConfig(): HasOne
    {
        return $this->hasOne(WmsConfig::class, 'warehouse_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }
}
