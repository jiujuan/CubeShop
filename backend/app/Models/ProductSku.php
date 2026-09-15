<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品 SKU
 */
class ProductSku extends Model
{
    use SoftDeletes;

    protected $table = 'product_skus';
    protected $fillable = ['product_id', 'sku_code', 'specs', 'price', 'status'];

    protected $casts = [
        'specs' => 'array',
        'price' => 'decimal:2',
    ];

    public function inventory()
    {
        return $this->hasOne(Inventory::class, 'sku_id');
    }
}
