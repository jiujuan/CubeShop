<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 订单超时自动取消 + 充值单超时关闭（收银台方案 §7.3）：每分钟一次，覆盖 order / recharge
// （订单分支复用 OrderService::cancelExpired：释放锁定库存 + 关支付单 + 订单流水）
Schedule::command('payments:cancel-timeout')->everyMinute()->withoutOverlapping();

// 主动查单补偿（收银台方案 §7.2）：每分钟扫描卡在处理中的在线支付单
Schedule::command('payments:sync-pending')->everyMinute()->withoutOverlapping();

// 订单自动确认收货（V1.1 T-003）：每小时检查 shipped 超期订单
Schedule::command('orders:auto-complete')->hourly()->withoutOverlapping();

// 优惠券过期收敛（V1.1 二期 T-033 / T-037）：每小时把过期未用券置 expired
Schedule::command('coupons:expire')->hourly()->withoutOverlapping();

// 物流轨迹拉取（V1.1 二期 T-045）：每 30 分钟拉取在途运单轨迹；未配置渠道时命令内部安全跳过
Schedule::command('shipping:pull-traces')->everyThirtyMinutes()->withoutOverlapping();
