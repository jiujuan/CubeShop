<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 库存流水
 */
class InventoryLog extends Model
{
    public $timestamps = false;

    protected $table = 'inventory_logs';
    protected $fillable = [
        'sku_id', 'change_type', 'change_qty',
        'before_stock', 'after_stock', 'before_locked', 'after_locked',
        'biz_type', 'biz_id', 'remark', 'operator_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
