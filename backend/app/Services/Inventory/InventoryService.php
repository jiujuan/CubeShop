<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessException;

/**
 * 库存服务（Roadmap P3）
 *
 * 能力：查询 / 锁定 / 释放 / 扣减 / 调整
 * 约束：全程事务；inventory_logs 全量流水；WHERE stock >= ? 条件更新防超卖
 *
 * 库存模型：stock（可售）+ locked_stock（下单未支付锁定）
 * - lock：可售 → 锁定（下单）
 * - release：锁定 → 可售（取消/超时）
 * - deduct：锁定 → 扣减（支付成功，锁定数量直接减少，可售不变）
 */
class InventoryService
{
    /** 查询可售库存 */
    public function getStock(int $skuId): int
    {
        return (int) (Inventory::where('sku_id', $skuId)->value('stock') ?? 0);
    }

    /** 库存是否充足 */
    public function isSufficient(int $skuId, int $qty): bool
    {
        if ($qty <= 0) {
            return false;
        }

        return Inventory::query()
            ->where('sku_id', $skuId)
            ->where('stock', '>=', $qty)
            ->exists();
    }

    /**
     * 锁定库存（可售 → 锁定）。防超卖：条件更新 stock >= qty
     *
     * @throws RuntimeException 库存不足
     */
    public function lock(int $skuId, int $qty, string $bizType = 'order', ?int $bizId = null, ?string $remark = null): void
    {
        if ($qty <= 0) {
            throw BusinessException::badRequest('锁定数量必须大于 0');
        }

        $beforeStock = DB::transaction(function () use ($skuId, $qty, $bizType, $bizId, $remark) {
            $inventory = Inventory::where('sku_id', $skuId)->lockForUpdate()->first();
            $before = $inventory?->stock ?? 0;

            // 条件更新：只有 stock >= qty 才会生效（防超卖）
            $affected = Inventory::query()
                ->where('sku_id', $skuId)
                ->where('stock', '>=', $qty)
                ->update([
                    'stock' => DB::raw("stock - {$qty}"),
                    'locked_stock' => DB::raw("locked_stock + {$qty}"),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw BusinessException::conflict('库存不足');
            }

            $this->log($skuId, 'lock', -$qty, $before, $before - $qty,
                ($inventory?->locked_stock ?? 0), ($inventory?->locked_stock ?? 0) + $qty,
                $bizType, $bizId, $remark);

            return $before;
        });

        // V1.1 F02 / T-018：可售库存「跌破」阈值 → 通知运营（事务提交后，仅穿越时触发一次）
        $this->maybeAlertLowStock($skuId, $beforeStock, $beforeStock - $qty);
    }

    /** 释放锁定库存（锁定 → 可售），用于取消/超时 */
    public function release(int $skuId, int $qty, string $bizType = 'cancel', ?int $bizId = null, ?string $remark = null): void
    {
        if ($qty <= 0) {
            throw BusinessException::badRequest('释放数量必须大于 0');
        }

        DB::transaction(function () use ($skuId, $qty, $bizType, $bizId, $remark) {
            $inventory = Inventory::where('sku_id', $skuId)->lockForUpdate()->first();
            $beforeStock = $inventory?->stock ?? 0;
            $beforeLocked = $inventory?->locked_stock ?? 0;

            Inventory::query()
                ->where('sku_id', $skuId)
                ->where('locked_stock', '>=', $qty)
                ->update([
                    'stock' => DB::raw("stock + {$qty}"),
                    'locked_stock' => DB::raw("locked_stock - {$qty}"),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            $this->log($skuId, 'unlock', $qty, $beforeStock, $beforeStock + $qty,
                $beforeLocked, $beforeLocked - $qty, $bizType, $bizId, $remark);
        });
    }

    /** 扣减库存（支付成功：锁定数量直接减少） */
    public function deduct(int $skuId, int $qty, string $bizType = 'order', ?int $bizId = null, ?string $remark = null): void
    {
        if ($qty <= 0) {
            throw BusinessException::badRequest('扣减数量必须大于 0');
        }

        DB::transaction(function () use ($skuId, $qty, $bizType, $bizId, $remark) {
            $inventory = Inventory::where('sku_id', $skuId)->lockForUpdate()->first();
            $beforeLocked = $inventory?->locked_stock ?? 0;

            Inventory::query()
                ->where('sku_id', $skuId)
                ->where('locked_stock', '>=', $qty)
                ->update([
                    'locked_stock' => DB::raw("locked_stock - {$qty}"),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            $this->log($skuId, 'deduct', -$qty, null, null,
                $beforeLocked, $beforeLocked - $qty, $bizType, $bizId, $remark);
        });
    }

    /**
     * 调整库存（后台管理：delta 可正可负）。防超卖：扣减时要求 stock >= |delta|
     *
     * @throws RuntimeException 库存不足
     */
    public function adjust(int $skuId, int $delta, ?int $operatorId = null, ?string $remark = null): int
    {
        return DB::transaction(function () use ($skuId, $delta, $operatorId, $remark) {
            if ($delta === 0) {
                return $this->getStock($skuId);
            }

            $inventory = Inventory::where('sku_id', $skuId)->lockForUpdate()->first();
            $before = $inventory?->stock ?? 0;

            $query = Inventory::query()->where('sku_id', $skuId);
            if ($delta < 0) {
                $query->where('stock', '>=', abs($delta));
            }

            $affected = $query->update([
                'stock' => DB::raw(sprintf('stock + (%d)', $delta)),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

            if ($affected === 0) {
                throw BusinessException::conflict('库存不足，无法调整');
            }

            $after = $before + $delta;
            $this->log($skuId, 'adjust', $delta, $before, $after,
                $inventory?->locked_stock ?? 0, $inventory?->locked_stock ?? 0,
                'adjust', null, $remark, $operatorId);

            return $after;
        });
    }

    /** SKU 批量查询库存：skuId => stock */
    public function getStockMap(array $skuIds): array
    {
        return Inventory::whereIn('sku_id', $skuIds)->pluck('stock', 'sku_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** V1.1 F02 / T-018：可售库存跌破阈值时派发预警事件（仅穿越阈值时触发） */
    private function maybeAlertLowStock(int $skuId, int $beforeStock, int $afterStock): void
    {
        if ($afterStock < 0 || $afterStock >= $beforeStock) {
            return;
        }

        $threshold = (int) app(\App\Services\Common\ConfigService::class)->getInt('inventory.warning_threshold', 10);
        if ($beforeStock <= $threshold || $afterStock > $threshold) {
            return; // 未发生「穿越」（之前已在阈值内，或仍未跌破）
        }

        $sku = \App\Models\ProductSku::find($skuId);
        if (! $sku) {
            return;
        }

        try {
            event(new \App\Events\LowStockAlert($sku, $afterStock));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 写流水 */
    private function log(
        int $skuId,
        string $changeType,
        int $changeQty,
        ?int $beforeStock,
        ?int $afterStock,
        ?int $beforeLocked,
        ?int $afterLocked,
        string $bizType,
        ?int $bizId,
        ?string $remark = null,
        ?int $operatorId = null,
    ): void {
        InventoryLog::create([
            'sku_id' => $skuId,
            'change_type' => $changeType,
            'change_qty' => $changeQty,
            'before_stock' => $beforeStock,
            'after_stock' => $afterStock,
            'before_locked' => $beforeLocked,
            'after_locked' => $afterLocked,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'remark' => $remark,
            'operator_id' => $operatorId,
            'created_at' => now(),
        ]);
    }
}
