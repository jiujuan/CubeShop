<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 发货单行项目（WMS 计划 P1）
 *
 * 建单时从 `order_items` 快照固化：平台编码与 WMS 货品编码都存字符串，
 * 后续商品改名/下架/改绑映射都不影响已推送单据。
 */
class FulfillmentOrderItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fulfillment_order_items';

    protected $fillable = [
        'fulfillment_order_id', 'sku_id', 'platform_sku_code', 'wms_sku_code',
        'product_name', 'qty', 'shipped_qty', 'barcode',
    ];

    protected $casts = [
        'sku_id' => 'integer',
        'qty' => 'integer',
        'shipped_qty' => 'integer',
    ];

    public function fulfillmentOrder(): BelongsTo
    {
        return $this->belongsTo(FulfillmentOrder::class, 'fulfillment_order_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }
}
