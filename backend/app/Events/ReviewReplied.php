<?php

namespace App\Events;

use App\Models\Review;
use Illuminate\Foundation\Events\Dispatchable;

/** 商家回复评价（V1.1 F02 / T-018） */
class ReviewReplied
{
    use Dispatchable;

    public function __construct(public Review $review)
    {
    }
}
