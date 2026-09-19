<?php

use App\Exceptions\BusinessException;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\SysOperationLog;
use App\Models\WmsInventoryDiff;
use App\Models\WmsInventorySnapshot;
use App\Models\Warehouse;
use App\Services\Wms\WmsReconcileService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WMS 库存对账服务（WMS 计划 P5 / §4.1）
 *
 * 核心断言：无差异不建单、有差异建单、重复对账不重复开单、
 * 处置幂等且只在 apply 时动库存（走 InventoryService 留痕）。
 */

/** 建仓 + 一条快照 + 平台库存，返回 [warehouseId, sku, snapshotRow] */
function p5Snapshot(int $platformQty, int $wmsQty): array
{
    $warehouse = Warehouse::create(['code' => 'WH_RC_'.uniqid(), 'name' => '对账仓', 'status' => 1]);
    $sku = createTestSku(stock: $platformQty);

    $snapshot = WmsInventorySnapshot::create([
        'warehouse_id' => $warehouse->id,
        'sku_id' => $sku->id,
        'wms_sku_code' => $sku->sku_code,
        'available_qty' => $wmsQty,
        'locked_qty' => 0,
        'synced_at' => now(),
    ]);

    // 对账只扫启用仓的快照
    \App\Models\WmsConfig::create([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'K', 'customer_id' => 'C', 'api_env' => 'sandbox', 'warehouse_code' => 'W',
        'callback_token' => 't'.bin2hex(random_bytes(8)),
    ]);

    return [$warehouse->id, $sku, $snapshot];
}

test('P5R-01 无差异不生成记录', function () {
    p5Snapshot(10, 10);

    expect(app(WmsReconcileService::class)->daily())->toBe(0)
        ->and(WmsInventoryDiff::count())->toBe(0);
});

test('P5R-02 有差异生成 pending 记录，diff = WMS - 平台', function () {
    [$warehouseId, $sku] = p5Snapshot(10, 7);

    expect(app(WmsReconcileService::class)->daily())->toBe(1);

    $diff = WmsInventoryDiff::first();
    expect($diff->warehouse_id)->toBe($warehouseId)
        ->and($diff->sku_id)->toBe($sku->id)
        ->and($diff->platform_qty)->toBe(10)
        ->and($diff->wms_qty)->toBe(7)
        ->and($diff->diff)->toBe(-3)
        ->and($diff->status)->toBe(WmsInventoryDiff::STATUS_PENDING);
});

test('P5R-03 重复对账不重复开单（同一 SKU 只有一条 pending，数值刷新）', function () {
    p5Snapshot(10, 7);
    $svc = app(WmsReconcileService::class);

    $svc->daily();
    // 期间平台卖了 2 件，WMS 侧未变 → 差异收窄为 -1
    Inventory::where('sku_id', WmsInventoryDiff::first()->sku_id)->update(['stock' => 8]);
    $created = $svc->daily();

    expect($created)->toBe(0)
        ->and(WmsInventoryDiff::count())->toBe(1)
        ->and(WmsInventoryDiff::first()->diff)->toBe(-1);
});

test('P5R-04 resolve（apply）按处置当下的平台库存校准，并留 inventory_logs', function () {
    [$warehouseId, $sku] = p5Snapshot(10, 7);
    $reconcile = app(WmsReconcileService::class);
    $reconcile->daily();
    $diff = WmsInventoryDiff::first();

    // 处置前平台又卖了 2 件（10 → 8），delta 应重新算成 -1 而非建单时的 -3
    Inventory::where('sku_id', $sku->id)->update(['stock' => 8]);

    $handled = $reconcile->resolve((int) $diff->id, 1);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(7)
        ->and($handled->status)->toBe(WmsInventoryDiff::STATUS_RESOLVED)
        ->and($handled->platform_qty)->toBe(8)
        ->and($handled->handled_by)->toBe(1)
        ->and($handled->handled_at)->not->toBeNull();

    $log = InventoryLog::where('sku_id', $sku->id)->latest('id')->first();
    expect($log->biz_type)->toBe('wms_reconcile')
        ->and($log->remark)->toBe(WmsReconcileService::REMARK_RECONCILE);
});

test('P5R-05 resolve（apply=false）只关单不调库存', function () {
    [$warehouseId, $sku] = p5Snapshot(10, 4);
    $reconcile = app(WmsReconcileService::class);
    $reconcile->daily();

    $handled = $reconcile->resolve((int) WmsInventoryDiff::first()->id, 1, false, '人工已核对，暂不校准');

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(10)
        ->and($handled->status)->toBe(WmsInventoryDiff::STATUS_RESOLVED)
        ->and($handled->remark)->toBe('人工已核对，暂不校准');
});

test('P5R-06 ignore 关单且绝不调库存', function () {
    [$warehouseId, $sku] = p5Snapshot(10, 99);
    $reconcile = app(WmsReconcileService::class);
    $reconcile->daily();

    $handled = $reconcile->ignore((int) WmsInventoryDiff::first()->id, 1, '已知在途差');

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(10)
        ->and($handled->status)->toBe(WmsInventoryDiff::STATUS_IGNORED);
});

test('P5R-07 处置幂等：已处置的差异重复 resolve 抛 409，不二次调库存', function () {
    [$warehouseId, $sku] = p5Snapshot(10, 7);
    $reconcile = app(WmsReconcileService::class);
    $reconcile->daily();
    $id = (int) WmsInventoryDiff::first()->id;

    $reconcile->resolve($id, 1);
    $stockAfterFirst = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    expect(fn () => $reconcile->resolve($id, 1))->toThrow(BusinessException::class)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockAfterFirst);
});

test('P5R-08 resolve 落审计（谁处置的、差多少、是否调了库存）', function () {
    p5Snapshot(10, 7);
    $reconcile = app(WmsReconcileService::class);
    $reconcile->daily();

    $reconcile->resolve((int) WmsInventoryDiff::first()->id, 7);

    $log = SysOperationLog::where('action', 'inventory_diff_resolved')->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe(7)
        ->and(json_decode($log->content, true)['adjusted'])->toBeTrue();
});

test('P5R-09 差异记录不存在 → 404 BusinessException', function () {
    expect(fn () => app(WmsReconcileService::class)->resolve(999999, 1))
        ->toThrow(BusinessException::class);
});

test('P5R-10 未启用仓库的快照不参与对账', function () {
    [$warehouseId, $sku] = p5Snapshot(10, 3);
    \App\Models\WmsConfig::where('warehouse_id', $warehouseId)->update(['enabled' => false]);

    expect(app(WmsReconcileService::class)->daily())->toBe(0)
        ->and(WmsInventoryDiff::count())->toBe(0);
});
