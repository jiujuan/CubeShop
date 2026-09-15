<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** 订单支付成功（V1.1 F02 / T-018） */
class OrderPaid
{
    use Dispatchable;

    public function __construct(public Order $order)
    {
    }
}
