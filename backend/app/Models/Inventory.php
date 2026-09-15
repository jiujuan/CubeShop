<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 库存（按 SKU）
 */
class Inventory extends Model
{
    public $timestamps = false;

    protected $table = 'inventories';
    protected $fillable = ['sku_id', 'stock', 'locked_stock'];

    protected $casts = [
        'updated_at' => 'datetime',
    ];
}
