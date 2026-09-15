<?php

namespace App\Listeners;

use App\Events\ReviewReplied;
use App\Services\Notification\NotificationService;

/** 商家回复评价 → 通知买家（V1.1 F02 / T-018） */
class SendReviewRepliedNotification
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(ReviewReplied $event): void
    {
        $review = $event->review;
        $this->notifications->send(
            $review->user_id,
            NotificationService::TYPE_REVIEW_REPLIED,
            '商家回复了你的评价',
            '你的商品评价收到了商家回复，快去看看吧。',
            "/product/{$review->product_id}",
        );
    }
}
