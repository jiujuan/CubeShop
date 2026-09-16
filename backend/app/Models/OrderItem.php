<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 订单明细（含商品快照，数据库设计 2.6）
 */
class OrderItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'order_items';
    protected $fillable = [
        'order_id', 'product_id', 'sku_id',
        'product_title', 'sku_specs', 'sku_image',
        'price', 'quantity', 'total_amount',
        'coupon_share', 'promotion_share',
    ];

    protected $casts = [
        'sku_specs' => 'array',
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'coupon_share' => 'decimal:2',
        'promotion_share' => 'decimal:2',
    ];

    /** 该行实付（下单单价 × 数量 − 券分摊 − 满减分摊），退款按此计算 */
    public function payableAmount(): float
    {
        return round(
            (float) $this->price * (int) $this->quantity
            - (float) $this->coupon_share
            - (float) $this->promotion_share,
            2,
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
