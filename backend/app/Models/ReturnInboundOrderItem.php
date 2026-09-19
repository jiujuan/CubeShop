<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 退货入库单行项目（WMS 计划 P4）
 *
 * 建单时从 `refunds.return_details` 快照固化：平台编码与 WMS 货品编码都存字符串。
 * `received_qty` / `inventory_type` 由收货回传填写：ZP 正品回可售库存，CC 残次转人工。
 */
class ReturnInboundOrderItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'return_inbound_order_items';

    protected $fillable = [
        'return_inbound_order_id', 'sku_id', 'platform_sku_code', 'wms_sku_code',
        'product_name', 'qty', 'received_qty', 'inventory_type', 'barcode',
    ];

    protected $casts = [
        'sku_id' => 'integer',
        'qty' => 'integer',
        'received_qty' => 'integer',
    ];

    public function returnInboundOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnInboundOrder::class, 'return_inbound_order_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }
}
