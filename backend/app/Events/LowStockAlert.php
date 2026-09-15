<?php

namespace App\Events;

use App\Models\ProductSku;
use Illuminate\Foundation\Events\Dispatchable;

/** 库存预警（V1.1 F02 / T-018，通知运营角色） */
class LowStockAlert
{
    use Dispatchable;

    public function __construct(public ProductSku $sku, public int $stock)
    {
    }
}
