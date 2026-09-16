<?php

namespace App\Console\Commands;

use App\Models\BalanceRecharge;
use App\Models\Order;
use App\Services\Common\ConfigService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * 支付超时关单（收银台方案 §7.3）
 *
 * 调度：每分钟执行（routes/console.php，withoutOverlapping）
 * 规则（按 biz_type 分流）：
 * - order    ：pending_payment 且创建超过 order.timeout_minutes → 复用 OrderService::cancelExpired
 *              （释放锁定库存 + 关闭待支付单 + 写订单流水）
 * - recharge ：balance_recharges.status=pending 且 expired_at < now → 关支付单 + status=closed
 */
class CancelTimeoutPayments extends Command
{
    protected $signature = 'payments:cancel-timeout {--dry-run : 仅统计不执行}';

    protected $description = '关闭超时未支付的订单支付单与充值单';

    public function handle(OrderService $orders, ConfigService $config): int
    {
        $orderTimeout = max(1, $config->getInt('order.timeout_minutes', 30));

        $orderQuery = Order::query()
            ->where('status', Order::STATUS_PENDING_PAYMENT)
            ->where('created_at', '<', now()->subMinutes($orderTimeout));

        $rechargeQuery = BalanceRecharge::query()
            ->where('status', BalanceRecharge::STATUS_PENDING)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now());

        $orderCount = (clone $orderQuery)->count();
        $rechargeCount = (clone $rechargeQuery)->count();

        if ($this->option('dry-run')) {
            $this->info("待处理：超时订单 {$orderCount} 笔（timeout={$orderTimeout}min）；超时充值单 {$rechargeCount} 笔");

            return self::SUCCESS;
        }

        if ($orderCount === 0 && $rechargeCount === 0) {
            $this->info('没有超时未支付的订单或充值单');

            return self::SUCCESS;
        }

        // 订单分支：复用既有超时取消（含库存释放与支付单关闭）
        $cancelled = 0;
        $orderQuery->orderBy('id')->chunkById(100, function ($chunk) use ($orders, &$cancelled) {
            foreach ($chunk as $order) {
                if ($orders->cancelExpired($order)) {
                    $cancelled++;
                    $this->line("已取消超时订单 {$order->order_no}");
                }
            }
        });

        // 充值分支：关支付单 + 充值单置 closed（不入账）
        $closed = 0;
        $rechargeQuery->orderBy('id')->chunkById(100, function ($chunk) use (&$closed) {
            foreach ($chunk as $recharge) {
                PaymentService::closePendingForRecharge($recharge);
                $recharge->forceFill(['status' => BalanceRecharge::STATUS_CLOSED])->save();
                $closed++;
                $this->line("已关闭超时充值单 {$recharge->recharge_no}");
            }
        });

        $this->info("处理完成：订单取消 {$cancelled}/{$orderCount} 笔；充值单关闭 {$closed}/{$rechargeCount} 笔");

        return self::SUCCESS;
    }
}
