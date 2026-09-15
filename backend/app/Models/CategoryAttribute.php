<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 分类属性模板（V1.1 E01 / T-007）
 *
 * 定义「某分类下的商品需要填写/勾选哪些属性」。
 */
class CategoryAttribute extends Model
{
    protected $table = 'category_attributes';

    protected $fillable = ['category_id', 'attribute_id', 'is_required', 'sort'];

    protected $casts = [
        'is_required' => 'boolean',
        'sort' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
