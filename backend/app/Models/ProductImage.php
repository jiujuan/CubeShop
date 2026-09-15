<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商品图片
 */
class ProductImage extends Model
{
    public $timestamps = false;

    protected $table = 'product_images';
    protected $fillable = ['product_id', 'url', 'sort'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
