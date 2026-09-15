<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 属性库（V1.1 E01 / T-007）
 *
 * type=spec  规格属性：值参与 SKU 组合（颜色 / 尺码）
 * type=param 参数属性：记录在 product_attribute_values（材质 / 功率）
 */
class Attribute extends Model
{
    public const TYPE_SPEC = 'spec';
    public const TYPE_PARAM = 'param';

    public const TYPE_LABELS = [
        self::TYPE_SPEC => '规格属性',
        self::TYPE_PARAM => '参数属性',
    ];

    protected $table = 'attributes';

    protected $fillable = ['name', 'type', 'is_filterable', 'is_multiple', 'allow_custom', 'sort'];

    protected $casts = [
        'is_filterable' => 'boolean',
        'is_multiple' => 'boolean',
        'allow_custom' => 'boolean',
        'sort' => 'integer',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort')->orderBy('id');
    }

    public function categoryAttributes(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attributes')
            ->withPivot(['is_required', 'sort'])
            ->withTimestamps();
    }

    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function isSpec(): bool
    {
        return $this->type === self::TYPE_SPEC;
    }

    public function scopeFilterable($query)
    {
        return $query->where('is_filterable', true);
    }

    public function scopeSpec($query)
    {
        return $query->where('type', self::TYPE_SPEC);
    }

    public function scopeParam($query)
    {
        return $query->where('type', self::TYPE_PARAM);
    }
}
