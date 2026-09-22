<?php

namespace App\Models;

use App\Casts\MediaPath;
use App\Models\Traits\ReleasesMediaOnDelete;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品图片
 */
class ProductImage extends Model
{
    use ReleasesMediaOnDelete;

    public $timestamps = false;

    protected $table = 'product_images';
    protected $fillable = ['product_id', 'url', 'sort'];

    protected $casts = [
        'created_at' => 'datetime',
        'url' => MediaPath::class,
    ];
}
