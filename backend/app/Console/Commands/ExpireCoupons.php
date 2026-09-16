<?php

namespace App\Console\Commands;

use App\Services\Marketing\CouponService;
use Illuminate\Console\Command;

/**
 * 优惠券过期收敛（V1.1 二期 F06 / T-033 / T-037）
 *
 * 调度：每小时（routes/console.php）
 * 规则：`user_coupons.status=unused` 且 `expire_at < now()` → `expired`（分批 500）
 */
class ExpireCoupons extends Command
{
    protected $signature = 'coupons:expire';

    protected $description = '把已过期未使用的优惠券置为 expired';

    public function handle(CouponService $coupons): int
    {
        $count = $coupons->expireOverdue();

        $this->info($count > 0 ? "已过期优惠券：{$count} 张" : '没有需要过期的优惠券');

        return self::SUCCESS;
    }
}
