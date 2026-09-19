<?php

use App\Models\Inventory;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Models\WmsInventorySnapshot;
use App\Models\Warehouse;
use App\Services\Wms\WmsInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * WMS 库存同步服务（WMS 计划 P5 / §4.1）
 *
 * 核心断言：**默认绝不改平台库存**——WMS 数字只进快照，
 * 只有显式 apply 才动 `inventories`，且走 InventoryService 留痕。
 */

beforeEach(function () {
    config([
        'wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw',
        'wms.inventory.page_size' => 2,
    ]);
});

/** 建一个启用且凭证齐备的仓库配置（走真实 CainiaoAdapter + Http::fake） */
function p5Config(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_P5_'.uniqid(), 'name' => '同步仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'TEST_KEY',
        'customer_id' => 'CUBE_OWNER',
        'api_env' => 'sandbox',
        'warehouse_code' => 'CN-WH',
        'callback_token' => 'rtoken'.bin2hex(random_bytes(12)),
    ], $attrs));
    $config->app_secret = 'TEST_SECRET';
    $config->save();

    return $config->refresh();
}

/** 伪造库存查询回执（items 形态）。用闭包而非固定响应对象：多次请求每次现造，避免复用同一个 Promise */
function p5FakeInventory(array $quantities): void
{
    Http::fake([
        '*' => fn () => Http::response(json_encode([
            'response' => [
                'flag' => 'success',
                'code' => '0',
                'items' => [
                    'item' => collect($quantities)
                        ->map(fn ($qty, $code) => ['itemCode' => $code, 'quantity' => $qty])
                        ->values()
                        ->all(),
                ],
            ],
        ]), 200),
    ]);
}

test('P5S-01 同步写入快照：每个 SKU 一行，数量取 WMS 可用量', function () {
    $config = p5Config();
    $sku = createTestSku(stock: 10);
    p5FakeInventory([$sku->sku_code => 42]);

    $count = app(WmsInventorySyncService::class)->syncWarehouse($config);

    expect($count)->toBe(1);
    $snapshot = WmsInventorySnapshot::first();
    expect($snapshot->sku_id)->toBe($sku->id)
        ->and($snapshot->wms_sku_code)->toBe($sku->sku_code)
        ->and($snapshot->available_qty)->toBe(42)
        ->and($snapshot->synced_at)->not->toBeNull();
});

test('P5S-02 默认不改平台库存（防 WMS 脏数据污染售卖）', function () {
    $config = p5Config();
    $sku = createTestSku(stock: 10);
    p5FakeInventory([$sku->sku_code => 999]);

    app(WmsInventorySyncService::class)->syncWarehouse($config);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(10)
        ->and(WmsInventorySnapshot::first()->available_qty)->toBe(999);
});

test('P5S-03 --apply 才校准平台库存，且按差值调整（可正可负）', function () {
    $config = p5Config();
    $sku = createTestSku(stock: 10);
    p5FakeInventory([$sku->sku_code => 25]);

    app(WmsInventorySyncService::class)->syncWarehouse($config, [], true);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(25);
});

test('P5S-04 重复同步覆盖同一行快照（不累加、不新增）', function () {
    $config = p5Config();
    $sku = createTestSku(stock: 10);
    $svc = app(WmsInventorySyncService::class);

    // Http::fake 多次调用时首个 stub 优先（不是覆盖），故用引用捕获的可变响应模拟两次回执
    $quantities = [$sku->sku_code => 30];
    Http::fake(['*' => function () use (&$quantities) {
        return Http::response(json_encode([
            'response' => [
                'flag' => 'success',
                'code' => '0',
                'items' => ['item' => collect($quantities)
                    ->map(fn ($qty, $code) => ['itemCode' => $code, 'quantity' => $qty])
                    ->values()->all()],
            ],
        ]), 200);
    }]);

    $svc->syncWarehouse($config);
    $quantities = [$sku->sku_code => 8];
    $svc->syncWarehouse($config);

    expect(WmsInventorySnapshot::count())->toBe(1)
        ->and(WmsInventorySnapshot::first()->available_qty)->toBe(8);
});

test('P5S-05 分页拉取：page_size=2 时 5 个 SKU 分 3 批各发一次请求', function () {
    $config = p5Config();
    $skus = collect(range(1, 5))->map(fn () => createTestSku(stock: 5))->all();
    p5FakeInventory(collect($skus)->mapWithKeys(fn ($s) => [$s->sku_code => 7])->all());

    $count = app(WmsInventorySyncService::class)->syncWarehouse($config);

    expect($count)->toBe(5)
        ->and(WmsInventorySnapshot::count())->toBe(5);
    Http::assertSentCount(3);
});

test('P5S-06 查询失败：跳过该批并落 wms_api_logs，不抛异常', function () {
    $config = p5Config();
    createTestSku(stock: 5);
    Http::fake(['*' => fn () => Http::response(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S03', 'message' => '参数非法'],
    ]), 200)]);

    $svc = app(WmsInventorySyncService::class);
    $count = $svc->syncWarehouse($config);

    expect($count)->toBe(0)
        ->and(WmsInventorySnapshot::count())->toBe(0)
        ->and(WmsApiLog::where('api_name', 'queryInventory')->where('success', false)->count())->toBe(1)
        ->and($svc->errors())->not->toBeEmpty();
});

test('P5S-07 未启用的仓库直接跳过，不发请求', function () {
    $config = p5Config(['enabled' => false]);
    createTestSku(stock: 5);
    p5FakeInventory([]);

    $count = app(WmsInventorySyncService::class)->syncWarehouse($config);

    expect($count)->toBe(0);
    Http::assertNothingSent();
});

test('P5S-08 manual 映射模式下未映射的 SKU 跳过并记错误', function () {
    $config = p5Config(['sku_mapping_mode' => 'manual']);
    $sku = createTestSku(stock: 5);
    p5FakeInventory([$sku->sku_code => 3]);

    $count = app(WmsInventorySyncService::class)->syncWarehouse($config);

    expect($count)->toBe(0)
        ->and(WmsInventorySnapshot::count())->toBe(0);
    Http::assertNothingSent();
});

test('P5S-09 对方未返回的货品不写 0（避免「未同步」被读成「无货」）', function () {
    $config = p5Config();
    $sku = createTestSku(stock: 10);
    p5FakeInventory(['OTHER-CODE' => 5]);

    $count = app(WmsInventorySyncService::class)->syncWarehouse($config);

    expect($count)->toBe(0)
        ->and(WmsInventorySnapshot::count())->toBe(0);
});

test('P5S-10 syncAll 只处理启用仓库，返回 仓库=>条数 汇总', function () {
    $enabled = p5Config();
    p5Config(['enabled' => false]);
    $sku = createTestSku(stock: 10);
    p5FakeInventory([$sku->sku_code => 12]);

    $summary = app(WmsInventorySyncService::class)->syncAll();

    expect($summary)->toHaveCount(1)
        ->and($summary[(int) $enabled->warehouse_id])->toBe(1);
});

test('P5S-11 同步留审计（module=wms, action=inventory_sync）', function () {
    $config = p5Config();
    $sku = createTestSku(stock: 10);
    p5FakeInventory([$sku->sku_code => 12]);

    app(WmsInventorySyncService::class)->syncWarehouse($config);

    $log = \App\Models\SysOperationLog::where('action', 'inventory_sync')->first();
    expect($log)->not->toBeNull()
        ->and($log->module)->toBe('wms')
        ->and(json_decode($log->content, true)['synced'])->toBe(1);
});
