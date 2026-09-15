<?php

namespace App\Listeners;

use App\Events\OrderShipped;
use App\Services\Notification\NotificationService;

/** 订单发货 → 通知买家（V1.1 F02 / T-018） */
class SendOrderShippedNotification
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(OrderShipped $event): void
    {
        $order = $event->order;
        $this->notifications->send(
            $order->user_id,
            NotificationService::TYPE_ORDER_SHIPPED,
            '订单已发货',
            "订单 {$order->order_no} 已发货，请注意查收。",
            "/orders/{$order->id}",
        );
    }
}
