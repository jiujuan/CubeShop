<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        // V1.1 E01
        'brand_id', 'weight', 'video_url', 'keywords',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'brand_id' => 'integer',
        'weight' => 'integer',
    ];

    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** 品牌（V1.1 E01） */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** 商品参数值（V1.1 E01） */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
