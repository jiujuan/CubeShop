<?php

namespace App\Listeners;

use App\Events\LowStockAlert;
use App\Services\Notification\NotificationService;

/** 库存预警 → 通知运营角色（V1.1 F02 / T-018） */
class SendLowStockNotification
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(LowStockAlert $event): void
    {
        $sku = $event->sku;
        $this->notifications->sendToRole(
            'operator',
            NotificationService::TYPE_LOW_STOCK,
            '库存预警',
            "SKU {$sku->sku_code}（商品 #{$sku->product_id}）库存仅剩 {$event->stock} 件，请及时补货。",
            "/products/{$sku->product_id}/edit",
        );
    }
}
