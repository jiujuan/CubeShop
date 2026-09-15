<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 属性值（V1.1 E01 / T-007）
 */
class AttributeValue extends Model
{
    protected $table = 'attribute_values';

    protected $fillable = ['attribute_id', 'value', 'sort'];

    protected $casts = [
        'sort' => 'integer',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
