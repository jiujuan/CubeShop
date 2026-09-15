<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** 订单已发货（V1.1 F02 / T-018；二期补充快递单号） */
class OrderShipped
{
    use Dispatchable;

    public function __construct(public Order $order)
    {
    }
}
