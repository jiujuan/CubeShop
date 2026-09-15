<?php

namespace App\Listeners;

use App\Events\RefundResult;
use App\Services\Notification\NotificationService;

/** 退款结果 → 通知买家（V1.1 F02 / T-018） */
class SendRefundResultNotification
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(RefundResult $event): void
    {
        $refund = $event->refund;
        $label = match ($refund->status) {
            'success' => '退款成功',
            'rejected' => '退款被拒绝',
            default => '退款处理中',
        };
        $this->notifications->send(
            $refund->user_id,
            NotificationService::TYPE_REFUND_RESULT,
            $label,
            "退款单 {$refund->refund_no}：{$label}，金额 ¥{$refund->amount}。",
            $refund->order_id ? "/orders/{$refund->order_id}" : null,
        );
    }
}
