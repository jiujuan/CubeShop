<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Notification\NotificationService;

/** 支付成功 → 通知买家（V1.1 F02 / T-018） */
class SendOrderPaidNotification
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;
        $this->notifications->send(
            $order->user_id,
            NotificationService::TYPE_ORDER_PAID,
            '支付成功',
            "订单 {$order->order_no} 支付成功，我们将尽快为你发货。",
            "/orders/{$order->id}",
        );
    }
}
