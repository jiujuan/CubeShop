<?php

namespace App\Console\Commands;

use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Models\WmsConfig;
use Illuminate\Console\Command;

/**
 * 同步推送待推送的发货单（WMS 计划 P1 / Step 6 兜底命令）
 *
 * `queue:work` 未常驻（本地开发、轻量部署）时，用本命令把 `pending_push` 的发货单
 * 一次性推完；失败原因已由作业写入 `last_push_error` 与 `wms_api_logs`，这里只做汇总。
 *
 * 幂等：已推送/终态的单不会重复推送（作业内短路）。
 */
class WmsDrain extends Command
{
    protected $signature = 'wms:drain {--limit=100 : 单次最多处理条数}';

    protected $description = '同步推送待推送的发货单（无队列 worker 环境的兜底）';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $pending = FulfillmentOrder::where('status', FulfillmentOrder::STATUS_PENDING_PUSH)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'outbound_no', 'warehouse_id']);

        if ($pending->isEmpty()) {
            $this->info('没有待推送的发货单');

            return self::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;

        foreach ($pending as $fo) {
            try {
                dispatch_sync(new PushOutboundJob((int) $fo->id, $this->triesFor((int) $fo->warehouse_id)));
            } catch (\Throwable $e) {
                // 未达重试上限的失败以异常冒泡，已落 last_push_error，无需中断整批
                $this->warn("  {$fo->outbound_no} 推送未完成：{$e->getMessage()}");
            }

            $status = FulfillmentOrder::whereKey($fo->id)->value('status');
            if ($status === FulfillmentOrder::STATUS_PUSHED) {
                $pushed++;
                $this->line("  ✓ {$fo->outbound_no} 已推送");
            } else {
                $failed++;
                $this->error("  ✗ {$fo->outbound_no} 当前状态：{$status}");
            }
        }

        $this->info("处理完成：成功 {$pushed} 条，未成功 {$failed} 条");

        return self::SUCCESS;
    }

    /** 重试次数取自该仓配置（缺配置按 3） */
    private function triesFor(int $warehouseId): int
    {
        $config = WmsConfig::where('warehouse_id', $warehouseId)->first();

        return $config ? (int) $config->push_retry_times : 3;
    }
}
