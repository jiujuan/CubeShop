<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商品参数值（V1.1 E01 / T-007）
 *
 * 仅存参数类（param）属性的取值；规格类通过 SKU 的 specs 表达。
 */
class ProductAttributeValue extends Model
{
    protected $table = 'product_attribute_values';

    protected $fillable = ['product_id', 'attribute_id', 'value'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
