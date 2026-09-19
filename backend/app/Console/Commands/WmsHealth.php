<?php

namespace App\Console\Commands;

use App\Services\Wms\WmsHealthCheckService;
use Illuminate\Console\Command;

/**
 * WMS 健康巡检（WMS 计划 P5 / F5）
 *
 * 输出配置、卡死单据、失败单据、异常单据、接口失败率、队列积压六项指标。
 * `--alert` 才会把问题写审计并推站内信（调度用）；本地排查不加该参数，避免噪音。
 */
class WmsHealth extends Command
{
    protected $signature = 'wms:health {--alert : 发现问题时写审计日志并推送站内告警}';

    protected $description = 'WMS 健康巡检：配置/卡死/失败/异常/失败率/队列积压';

    public function handle(WmsHealthCheckService $health): int
    {
        $alert = (bool) $this->option('alert');
        $report = $alert ? $health->collectAndAlert() : $health->collect();

        $icons = [
            WmsHealthCheckService::STATUS_OK => '✓',
            WmsHealthCheckService::STATUS_WARNING => '!',
            WmsHealthCheckService::STATUS_ERROR => '✗',
            WmsHealthCheckService::STATUS_UNKNOWN => '?',
        ];

        foreach ($report['checks'] as $check) {
            $this->line(sprintf('  %s %s —— %s', $icons[$check['status']] ?? ' ', $check['title'], $check['detail']));
        }

        $this->newLine();
        $this->info(sprintf('巡检时间 %s：%s', $report['checked_at'], $report['healthy'] ? '健康' : '存在问题'));

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
