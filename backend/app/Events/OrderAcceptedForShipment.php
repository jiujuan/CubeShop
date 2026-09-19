<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 订单进入待发货（WMS 计划 P1 / Step 5 履约触发点）
 *
 * 由 `OrderService::acceptForShipment()` 在**事务提交后**派发，覆盖两条路径：
 * - 支付成功自动受理（`PaymentService`）；
 * - 后台人工受理异常滞留订单（`Admin\OrderController::accept`）。
 *
 * 监听方 `Listeners\Wms\CreateFulfillmentOrder` 只在 WMS 启用时介入，
 * 否则直接返回——既有履约链路行为零变化。
 */
class OrderAcceptedForShipment
{
    use Dispatchable;

    public function __construct(public Order $order)
    {
    }
}
