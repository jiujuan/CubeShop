<?php

namespace App\Services\Wms;

use App\Exceptions\BusinessException;
use App\Models\SysOperationLog;
use App\Models\WmsConfig;
use App\Models\WmsInventoryDiff;
use App\Models\WmsInventorySnapshot;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WMS 库存对账（WMS 计划 P5 / F3、F4）
 *
 * 把「平台可售库存 vs 最近一次 WMS 快照」的差值固化成一条待办，
 * 运营可选两种处置：
 * - `resolve($applyInventory=true)`：按 WMS 值校准平台库存（走 `InventoryService::adjust()`）；
 * - `ignore()`：认了这笔差异（例如已知的在途/盘点差），只关单不调库存。
 *
 * 幂等：
 * - 建单——`pending` 对 (warehouse_id, sku_id) 部分唯一，重复对账不会重复开单；
 * - 处置——只有 `pending` 可被处置，重入直接返回，不会二次调库存。
 */
class WmsReconcileService
{
    /** 对账校准的库存备注（inventory_logs 可检索来源） */
    public const REMARK_RECONCILE = 'WMS 库存对账校准';

    public function __construct(
        private readonly WmsInventorySyncService $sync,
        private readonly InventoryService $inventory,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 每日对账：扫描启用仓库的快照，生成/更新差异待办。
     *
     * @return int 本次新建的差异条数
     */
    public function daily(): int
    {
        $created = 0;

        foreach ($this->sync->enabledConfigs() as $config) {
            $created += $this->reconcileWarehouse($config);
        }

        $this->operationLog->record(
            null,
            'wms',
            'inventory_reconcile',
            'wms',
            null,
            ['created' => $created],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $created;
    }

    /** 单仓对账（按快照逐 SKU 比对） */
    public function reconcileWarehouse(WmsConfig $config): int
    {
        $created = 0;
        $warehouseId = (int) $config->warehouse_id;

        WmsInventorySnapshot::query()
            ->where('warehouse_id', $warehouseId)
            ->orderBy('sku_id')
            ->chunkById(200, function ($snapshots) use ($warehouseId, &$created) {
                $skuIds = $snapshots->pluck('sku_id')->all();
                $platform = $this->inventory->getStockMap($skuIds);

                foreach ($snapshots as $snapshot) {
                    $platformQty = (int) ($platform[$snapshot->sku_id] ?? 0);
                    $wmsQty = (int) $snapshot->available_qty;
                    $diff = $wmsQty - $platformQty;

                    if ($diff === 0) {
                        continue;
                    }

                    // 已有 pending：只刷新数值与快照时间，不另开单（部分唯一索引兜底并发）
                    $existing = WmsInventoryDiff::query()
                        ->where('warehouse_id', $warehouseId)
                        ->where('sku_id', $snapshot->sku_id)
                        ->where('status', WmsInventoryDiff::STATUS_PENDING)
                        ->first();

                    if ($existing) {
                        $existing->forceFill([
                            'platform_qty' => $platformQty,
                            'wms_qty' => $wmsQty,
                            'diff' => $diff,
                        ])->save();

                        continue;
                    }

                    WmsInventoryDiff::create([
                        'warehouse_id' => $warehouseId,
                        'sku_id' => $snapshot->sku_id,
                        'wms_sku_code' => (string) $snapshot->wms_sku_code,
                        'platform_qty' => $platformQty,
                        'wms_qty' => $wmsQty,
                        'diff' => $diff,
                        'status' => WmsInventoryDiff::STATUS_PENDING,
                    ]);
                    $created++;
                }
            });

        return $created;
    }

    /**
     * 校准：把平台库存改到 WMS 值并关单。
     *
     * ⚠️ 以**处置当下**的平台库存重新算 delta（对账后可能又卖了若干），
     * 直接用建单时的 diff 会覆盖掉期间的正常出入库。
     */
    public function resolve(int $diffId, int $operatorId, bool $applyInventory = true, ?string $remark = null): WmsInventoryDiff
    {
        $diff = $this->lockPending($diffId);

        $currentQty = (int) DB::table('inventories')->where('sku_id', $diff->sku_id)->value('stock');
        $delta = (int) $diff->wms_qty - $currentQty;
        $adjusted = false;

        if ($applyInventory && $delta !== 0) {
            $this->inventory->adjust((int) $diff->sku_id, $delta, null, self::REMARK_RECONCILE, 'wms_reconcile');
            $adjusted = true;
        }

        $diff->forceFill([
            'status' => WmsInventoryDiff::STATUS_RESOLVED,
            'platform_qty' => $currentQty,
            'handled_by' => $operatorId,
            'handled_at' => now(),
            'remark' => $remark ?? ($adjusted ? '按 WMS 可用量校准' : '无需调整'),
        ])->save();

        $this->operationLog->record(
            $operatorId,
            'wms',
            'inventory_diff_resolved',
            'wms_inventory_diff',
            (int) $diff->id,
            [
                'sku_id' => $diff->sku_id,
                'wms_sku_code' => $diff->wms_sku_code,
                'wms_qty' => $diff->wms_qty,
                'platform_qty_before' => $currentQty,
                'delta' => $delta,
                'adjusted' => $adjusted,
                'remark' => $remark,
            ],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $diff->refresh();
    }

    /** 忽略：认下差异，关单但不调库存 */
    public function ignore(int $diffId, int $operatorId, ?string $remark = null): WmsInventoryDiff
    {
        $diff = $this->lockPending($diffId);

        $diff->forceFill([
            'status' => WmsInventoryDiff::STATUS_IGNORED,
            'handled_by' => $operatorId,
            'handled_at' => now(),
            'remark' => $remark ?? '人工忽略',
        ])->save();

        $this->operationLog->record(
            $operatorId,
            'wms',
            'inventory_diff_ignored',
            'wms_inventory_diff',
            (int) $diff->id,
            ['sku_id' => $diff->sku_id, 'diff' => $diff->diff, 'remark' => $remark],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $diff->refresh();
    }

    // ---------------- 内部 ----------------

    /** 取待处理差异（非 pending 一律拒绝，保证处置幂等） */
    private function lockPending(int $diffId): WmsInventoryDiff
    {
        $diff = WmsInventoryDiff::find($diffId);
        if (! $diff) {
            throw BusinessException::notFound('库存差异记录不存在');
        }

        if (! $diff->isPending()) {
            throw BusinessException::conflict(sprintf(
                '该差异已处置（当前状态「%s」），不可重复处理',
                $diff->statusLabel(),
            ));
        }

        return $diff;
    }
}
