<?php

namespace App\Console\Commands;

use App\Services\Wms\WmsReconcileService;
use Illuminate\Console\Command;

/**
 * WMS 库存对账（WMS 计划 P5 / F3）
 *
 * 比对「平台可售库存 vs 最近一次 WMS 快照」，差异生成 `pending` 待办。
 * 同一仓库同一 SKU 同时只有一条 pending，因此重复执行天然幂等。
 *
 * 注：对账只**发现**差异，不自动改库存；处置走后台 `resolve`（可带校准）。
 */
class WmsReconcile extends Command
{
    protected $signature = 'wms:reconcile';

    protected $description = '比对平台库存与 WMS 快照，生成库存差异待办（不自动改库存）';

    public function handle(WmsReconcileService $reconcile): int
    {
        $created = $reconcile->daily();

        $this->info(sprintf('对账完成：新增 %d 条差异待办', $created));

        return self::SUCCESS;
    }
}
