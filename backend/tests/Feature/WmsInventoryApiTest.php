<?php

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\SysOperationLog;
use App\Models\WmsConfig;
use App\Models\WmsInventoryDiff;
use App\Models\WmsInventorySnapshot;
use App\Models\Warehouse;
use App\Services\Wms\WmsInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * WMS 库存后台接口（WMS 计划 P5 / Step 5）
 *
 * 覆盖：快照/差异列表、按 WMS 校准（走 InventoryService 留痕）、忽略、
 * 手工触发同步、权限，以及「不自动改平台库存」这条红线的接口侧验证。
 */

beforeEach(function () {
    seedRoles();
    config(['wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw']);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

function p5ApiWarehouse(): array
{
    $warehouse = Warehouse::create(['code' => 'WH_API_'.uniqid(), 'name' => '接口仓', 'status' => 1]);
    $config = WmsConfig::create([
        'warehouse_id' => $warehouse->id, 'provider' => 'cainiao', 'enabled' => true,
        'auto_push' => true, 'push_retry_times' => 3, 'sku_mapping_mode' => 'same',
        'app_key' => 'K', 'customer_id' => 'C', 'api_env' => 'sandbox', 'warehouse_code' => 'W',
        'callback_token' => 't'.bin2hex(random_bytes(8)),
    ]);
    $config->app_secret = 'SECRET';
    $config->save();

    return [$warehouse->id, $config->refresh()];
}

/** 造一条 pending 差异（平台 10 / WMS 7） */
function p5ApiDiff(int $warehouseId, int $platformQty = 10, int $wmsQty = 7): array
{
    $sku = createTestSku(stock: $platformQty);

    WmsInventorySnapshot::create([
        'warehouse_id' => $warehouseId, 'sku_id' => $sku->id, 'wms_sku_code' => $sku->sku_code,
        'available_qty' => $wmsQty, 'locked_qty' => 0, 'synced_at' => now(),
    ]);

    $diff = WmsInventoryDiff::create([
        'warehouse_id' => $warehouseId, 'sku_id' => $sku->id, 'wms_sku_code' => $sku->sku_code,
        'platform_qty' => $platformQty, 'wms_qty' => $wmsQty, 'diff' => $wmsQty - $platformQty,
        'status' => WmsInventoryDiff::STATUS_PENDING,
    ]);

    return [$diff, $sku];
}

test('TC-PI-001 快照列表返回 SKU 编码与可用量', function () {
    [$warehouseId] = p5ApiWarehouse();
    $sku = createTestSku(stock: 5);
    WmsInventorySnapshot::create([
        'warehouse_id' => $warehouseId, 'sku_id' => $sku->id, 'wms_sku_code' => $sku->sku_code,
        'available_qty' => 33, 'locked_qty' => 2, 'synced_at' => now(),
    ]);

    $list = $this->getJson('/api/admin/wms/inventory/snapshots', $this->adminAuth)->assertOk()->json('data');

    expect($list['list'][0]['available_qty'])->toBe(33)
        ->and($list['list'][0]['sku_code'])->toBe($sku->sku_code)
        ->and($list['list'][0]['warehouse_id'])->toBe($warehouseId);
});

test('TC-PI-002 差异列表按 |diff| 降序，含状态中文', function () {
    [$warehouseId] = p5ApiWarehouse();
    p5ApiDiff($warehouseId, 10, 7);   // -3
    p5ApiDiff($warehouseId, 10, 40);  // +30

    $list = $this->getJson('/api/admin/wms/inventory/diffs', $this->adminAuth)->assertOk()->json('data');

    expect($list['list'][0]['diff'])->toBe(30)
        ->and($list['list'][0]['status_label'])->toBe('待处理')
        ->and($list['list'][1]['diff'])->toBe(-3);
});

test('TC-PI-003 校准（apply）：平台库存改为 WMS 值 + inventory_logs 有来源备注', function () {
    [$warehouseId] = p5ApiWarehouse();
    [$diff, $sku] = p5ApiDiff($warehouseId, 10, 7);

    $this->postJson('/api/admin/wms/inventory/diffs/'.$diff->id.'/resolve', [
        'action' => 'resolve', 'apply' => true,
    ], $this->adminAuth)->assertOk();

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(7)
        ->and($diff->refresh()->status)->toBe(WmsInventoryDiff::STATUS_RESOLVED);

    $log = InventoryLog::where('sku_id', $sku->id)->latest('id')->first();
    expect($log->biz_type)->toBe('wms_reconcile')
        ->and($log->remark)->toBe(\App\Services\Wms\WmsReconcileService::REMARK_RECONCILE);
});

test('TC-PI-004 只关单不校准（apply=false）：库存纹丝不动', function () {
    [$warehouseId] = p5ApiWarehouse();
    [$diff, $sku] = p5ApiDiff($warehouseId, 10, 7);

    $this->postJson('/api/admin/wms/inventory/diffs/'.$diff->id.'/resolve', [
        'action' => 'resolve', 'apply' => false, 'remark' => '人工核对一致',
    ], $this->adminAuth)->assertOk();

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(10)
        ->and($diff->refresh()->status)->toBe(WmsInventoryDiff::STATUS_RESOLVED)
        ->and($diff->refresh()->remark)->toBe('人工核对一致');
});

test('TC-PI-005 忽略：状态 ignored 且不动库存', function () {
    [$warehouseId] = p5ApiWarehouse();
    [$diff, $sku] = p5ApiDiff($warehouseId, 10, 7);

    $this->postJson('/api/admin/wms/inventory/diffs/'.$diff->id.'/resolve', [
        'action' => 'ignore', 'remark' => '已知在途差',
    ], $this->adminAuth)->assertOk();

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(10)
        ->and($diff->refresh()->status)->toBe(WmsInventoryDiff::STATUS_IGNORED);
});

test('TC-PI-006 重复处置同一差异 → 409（不二次调库存）', function () {
    [$warehouseId] = p5ApiWarehouse();
    [$diff, $sku] = p5ApiDiff($warehouseId, 10, 7);

    $this->postJson('/api/admin/wms/inventory/diffs/'.$diff->id.'/resolve', [], $this->adminAuth)->assertOk();
    $this->postJson('/api/admin/wms/inventory/diffs/'.$diff->id.'/resolve', [], $this->adminAuth)->assertStatus(409);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(7);
});

test('TC-PI-007 处置落接口级审计（谁在后台点了什么）', function () {
    [$warehouseId] = p5ApiWarehouse();
    [$diff] = p5ApiDiff($warehouseId);

    $this->postJson('/api/admin/wms/inventory/diffs/'.$diff->id.'/resolve', ['action' => 'ignore'], $this->adminAuth)->assertOk();

    expect(SysOperationLog::where('action', 'inventory_diff_handle')->count())->toBe(1)
        ->and(SysOperationLog::where('action', 'inventory_diff_ignored')->count())->toBe(1);
});

test('TC-PI-008 手工触发同步：默认只写快照，平台库存不变', function () {
    [$warehouseId, $config] = p5ApiWarehouse();
    $sku = createTestSku(stock: 10);
    Http::fake(['*' => fn () => Http::response(json_encode([
        'response' => ['flag' => 'success', 'code' => '0', 'items' => ['item' => [
            ['itemCode' => $sku->sku_code, 'quantity' => 77],
        ]]],
    ]), 200)]);

    $data = $this->postJson('/api/admin/wms/inventory/sync', ['warehouse_id' => $warehouseId], $this->adminAuth)
        ->assertOk()->json('data');

    expect($data['summary'][0]['synced'])->toBe(1)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(10)
        ->and(WmsInventorySnapshot::first()->available_qty)->toBe(77)
        ->and(SysOperationLog::where('action', 'inventory_sync_manual')->count())->toBe(1);
});

test('TC-PI-009 无权限账号访问库存接口 → 403', function () {
    $user = createTestUser('p5inv');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->getJson('/api/admin/wms/inventory/snapshots', $auth)->assertStatus(403);
    $this->getJson('/api/admin/wms/inventory/diffs', $auth)->assertStatus(403);
    $this->getJson('/api/admin/wms/health', $auth)->assertStatus(403);
    $this->postJson('/api/admin/wms/inventory/diffs/1/resolve', [], $auth)->assertStatus(403);
});

test('TC-PI-010 手工同步带 apply=true 才校准（与默认行为形成对照）', function () {
    [$warehouseId] = p5ApiWarehouse();
    $sku = createTestSku(stock: 10);
    Http::fake(['*' => fn () => Http::response(json_encode([
        'response' => ['flag' => 'success', 'code' => '0', 'items' => ['item' => [
            ['itemCode' => $sku->sku_code, 'quantity' => 25],
        ]]],
    ]), 200)]);

    $this->postJson('/api/admin/wms/inventory/sync', [
        'warehouse_id' => $warehouseId, 'apply' => true,
    ], $this->adminAuth)->assertOk();

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(25);

    $log = InventoryLog::where('sku_id', $sku->id)->latest('id')->first();
    expect($log->biz_type)->toBe('wms_sync')
        ->and($log->remark)->toBe(WmsInventorySyncService::REMARK_SYNC);
});
