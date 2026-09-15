<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商品收藏（V1.1 F05 / T-024）
 */
class Favorite extends Model
{
    protected $table = 'favorites';

    protected $fillable = ['user_id', 'product_id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
