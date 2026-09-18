<?php

namespace App\Console\Commands;

use App\Models\Shipping;
use App\Services\Shipping\TracePullService;
use Illuminate\Console\Command;

/**
 * 物流轨迹拉取（V1.1 T-045，E03）
 *
 * - 范围：trace_status ∈ {pending, in_transit} 且 30 天内发货的运单（--include-failed 时含 failed 重试）；
 * - 分批 50 条，批间限流延时（渠道 QPS 限制）；
 * - 未配置渠道（SHIPPING_CHANNEL 为空 → NullChannel）时安全跳过并提示；
 * - 调度：每 30 分钟 withoutOverlapping（routes/console.php）。
 */
class PullShippingTraces extends Command
{
    protected $signature = 'shipping:pull-traces {--include-failed : 同时重试 failed 状态的运单}';

    protected $description = '拉取在途运单的物流轨迹（T-045）';

    public function handle(TracePullService $service): int
    {
        if (! config('services.shipping.channel')) {
            $this->info('未配置 SHIPPING_CHANNEL，跳过轨迹拉取（降级模式，发货与单号展示不受影响）');

            return self::SUCCESS;
        }

        $days = max(1, (int) config('services.shipping.pull_window_days', 30));
        $batchSize = max(1, (int) config('services.shipping.batch_size', 50));
        $delayMs = max(0, (int) config('services.shipping.batch_delay_ms', 200));

        $statuses = [Shipping::TRACE_PENDING, Shipping::TRACE_IN_TRANSIT];
        if ($this->option('include-failed')) {
            $statuses[] = Shipping::TRACE_FAILED;
        }

        $stats = ['pulled' => 0, 'failed' => 0, 'skipped' => 0];

        Shipping::query()
            ->whereIn('trace_status', $statuses)
            ->where('shipped_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById($batchSize, function ($shippings) use ($service, &$stats, $delayMs) {
                foreach ($shippings as $shipping) {
                    $stats[$service->pull($shipping)]++;
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            });

        $this->info(sprintf(
            '轨迹拉取完成：成功 %d，失败 %d，跳过 %d',
            $stats['pulled'],
            $stats['failed'],
            $stats['skipped']
        ));

        return self::SUCCESS;
    }
}
