<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品主表
 */
class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';
    protected $fillable = [
        'category_id', 'title', 'subtitle', 'main_image', 'description',
        'price', 'status', 'sales_count', 'sort',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function skus()
    {
        return $this->hasMany(ProductSku::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
