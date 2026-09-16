<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Common\ConfigService;
use App\Services\Order\OrderService;
use Illuminate\Console\Command;

/**
 * 订单超时自动取消（Roadmap P4）
 *
 * 调度：由 `payments:cancel-timeout` 每分钟调用（收银台方案 §7.3 起，本命令作为订单分支的实现，
 *       亦可单独手动执行 `php artisan orders:cancel-expired`）
 * 规则：pending_payment 且 created_at 早于 now - order.timeout_minutes（默认 30）
 */
class CancelExpiredOrders extends Command
{
    protected $signature = 'orders:cancel-expired {--dry-run : 仅统计不执行}';

    protected $description = '取消超时未支付的订单并释放库存';

    public function handle(OrderService $orders, ConfigService $config): int
    {
        $timeoutMinutes = max(1, $config->getInt('order.timeout_minutes', 30));
        $deadline = now()->subMinutes($timeoutMinutes);

        $query = Order::query()
            ->where('status', Order::STATUS_PENDING_PAYMENT)
            ->where('created_at', '<', $deadline);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("超时未支付订单：{$count} 笔（timeout={$timeoutMinutes}min）");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('没有超时未支付的订单');

            return self::SUCCESS;
        }

        $cancelled = 0;
        $query->orderBy('id')->chunkById(100, function ($chunk) use ($orders, &$cancelled) {
            foreach ($chunk as $order) {
                if ($orders->cancelExpired($order)) {
                    $cancelled++;
                    $this->line("已取消订单 {$order->order_no}");
                }
            }
        });

        $this->info("处理完成：共 {$count} 笔超时，成功取消 {$cancelled} 笔");

        return self::SUCCESS;
    }
}
