<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Common\ConfigService;
use App\Services\Order\OrderService;
use Illuminate\Console\Command;

/**
 * 订单自动确认收货（V1.1 E02-A / T-003）
 *
 * 调度：每小时执行（routes/console.php）
 * 规则：shipped 且 shipped_at <= now - order.auto_complete_days（默认 7 天）
 *
 * 分批（每批 200）处理，避免大表一次性载入；
 * 重复执行幂等（第二次处理数为 0）。
 */
class AutoCompleteOrders extends Command
{
    protected $signature = 'orders:auto-complete
                            {--dry-run : 仅统计不执行，用于上线前评估影响范围}
                            {--days= : 覆盖配置的自动确认天数}';

    protected $description = '自动确认收货：发货超过配置天数的订单流转为已完成';

    public function handle(OrderService $orders, ConfigService $config): int
    {
        $days = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : max(1, $config->getInt('order.auto_complete_days', 7));

        $deadline = now()->subDays($days);

        $query = Order::query()
            ->where('status', Order::STATUS_SHIPPED)
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', $deadline);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("待自动确认收货订单：{$count} 笔（auto_complete_days={$days}）");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('没有需要自动确认收货的订单');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $done = 0;

        $query->orderBy('id')->chunkById(200, function ($chunk) use ($orders, &$done) {
            foreach ($chunk as $order) {
                if ($orders->autoComplete($order)) {
                    $done++;
                    $this->line("已自动确认收货：{$order->order_no}");
                }
            }
        });

        $elapsed = round(microtime(true) - $started, 2);
        $this->info("处理完成：待处理 {$count} 笔，成功 {$done} 笔，耗时 {$elapsed}s");

        return self::SUCCESS;
    }
}
