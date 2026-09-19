<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 分类可选品牌（分类 ↔ 品牌 多对多，2026-09-19）
 *
 * 品牌与分类是两个正交属性、互不隶属：一个品牌可挂在多个分类下，
 * 一个分类下可有多个品牌。本表回答「该分类可选哪些品牌」。
 */
class CategoryBrand extends Model
{
    protected $table = 'category_brands';

    protected $fillable = ['category_id', 'brand_id', 'sort'];

    protected $casts = [
        'sort' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
