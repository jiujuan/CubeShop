<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 品牌（V1.1 E01 / T-007）
 */
class Brand extends Model
{
    protected $table = 'brands';

    protected $fillable = ['name', 'logo', 'sort', 'status'];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }
}
