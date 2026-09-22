<?php

namespace App\Models;

use App\Casts\MediaPath;
use App\Models\Traits\HasPublicId;
use App\Models\Traits\ReleasesMediaOnDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 品牌（V1.1 E01 / T-007）
 */
class Brand extends Model
{
    use HasPublicId, ReleasesMediaOnDelete;
    protected $table = 'brands';

    protected $fillable = ['name', 'logo', 'sort', 'status'];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
        'logo' => MediaPath::class,
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
