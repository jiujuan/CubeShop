<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 浏览足迹（V1.1 F05 / T-024）
 * 同一用户 + 商品唯一，browsed_at 更新为最近一次浏览时间。
 */
class BrowseHistory extends Model
{
    protected $table = 'browse_histories';

    protected $fillable = ['user_id', 'product_id', 'browsed_at'];

    protected $casts = [
        'browsed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
