<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 运单包裹明细（WMS 计划 P3 / Step 1）
 *
 * 菜鸟回传可能拆多包裹：`shippings` 主表只存主运单号（订单链路零变化），
 * 全量包裹落本表，主表包裹对应 sort=0。
 *
 * 出口 int id（后台内部数据，无 public_id 需求）。
 */
class ShippingPackage extends Model
{
    protected $table = 'shipping_packages';

    protected $fillable = [
        'shipping_id', 'tracking_no', 'carrier_code', 'carrier_name',
        'weight', 'items', 'sort',
    ];

    protected $casts = [
        'weight' => 'decimal:3',
        'items' => 'array',
        'sort' => 'integer',
    ];

    public function shipping(): BelongsTo
    {
        return $this->belongsTo(Shipping::class, 'shipping_id');
    }
}
