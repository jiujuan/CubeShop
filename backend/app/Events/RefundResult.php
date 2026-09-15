<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/** 退款处理结果（V1.1 F02 / T-018） */
class RefundResult
{
    use Dispatchable;

    public function __construct(public Refund $refund)
    {
    }
}
