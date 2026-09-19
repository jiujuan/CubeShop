<?php

namespace App\Console\Commands;

use App\Models\WmsConfig;
use App\Services\Wms\WmsInventorySyncService;
use Illuminate\Console\Command;

/**
 * WMS 库存同步（WMS 计划 P5 / F2、F3）
 *
 * 默认**只写快照不动平台库存**——WMS 侧数据可能脏，直接覆盖会制造超卖。
 * 只有显式 `--apply` 才按 WMS 可用量校准平台库存（每笔走
 * `InventoryService::adjust()` 带 `wms_sync` 备注）。
 */
class WmsSyncInventory extends Command
{
    protected $signature = 'wms:sync-inventory
        {warehouseId? : 仓库 ID（留空 = 所有启用仓库）}
        {--sku=* : 只同步指定 SKU（可多次），留空 = 全量}
        {--apply : 按 WMS 可用量校准平台库存（默认只写快照）}';

    protected $description = '拉取 WMS 可用库存并写入快照（默认不改平台库存，--apply 才校准）';

    public function handle(WmsInventorySyncService $sync): int
    {
        $skuIds = array_map('intval', (array) $this->option('sku'));
        $apply = (bool) $this->option('apply');
        $warehouseId = $this->argument('warehouseId');

        if ($warehouseId !== null) {
            $config = WmsConfig::where('warehouse_id', (int) $warehouseId)->first();
            if (! $config) {
                $this->error("仓库 #{$warehouseId} 没有 WMS 配置");

                return self::FAILURE;
            }

            $count = $sync->syncWarehouse($config, $skuIds, $apply);
            $this->info(sprintf('仓库 #%d 同步完成：%d 条快照%s', $config->warehouse_id, $count, $apply ? '（已校准平台库存）' : '（未改平台库存）'));
        } else {
            $summary = $sync->syncAll($skuIds, $apply);
            foreach ($summary as $id => $count) {
                $this->info(sprintf('仓库 #%d：%d 条快照', $id, $count));
            }
            $this->info(sprintf('合计 %d 条快照%s', array_sum($summary), $apply ? '（已校准平台库存）' : '（未改平台库存）'));
        }

        foreach ($sync->errors() as $error) {
            $this->warn('  ! '.$error);
        }

        return self::SUCCESS;
    }
}
