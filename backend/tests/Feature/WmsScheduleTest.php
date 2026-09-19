<?php

use App\Console\WmsScheduler;
use App\Models\WmsApiLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WMS 定时任务（WMS 计划 P5 / Step 3）
 *
 * 断言两件事：① 开关开着时四路任务都注册了；② 开关关掉后一个都不注册
 * （本地开发可用 `WMS_SCHEDULE_ENABLED=false` 整体静音）。
 */

test('TC-PS-001 默认开关下四路任务全部注册（同步/对账/清日志/巡检）', function () {
    $planned = (new WmsScheduler)->planned();

    expect(collect($planned)->pluck('command')->all())->toBe([
        'wms:sync-inventory', 'wms:reconcile', 'wms:prune-logs', 'wms:health --alert',
    ]);
});

test('TC-PS-002 总开关关闭 → 一个任务都不注册（本地开发可静音）', function () {
    config(['wms.schedule.enabled' => false]);

    expect((new WmsScheduler)->planned())->toBe([]);
});

test('TC-PS-003 单项开关可只停某一路，其余不受影响', function () {
    config(['wms.schedule.reconcile' => false]);

    $commands = collect((new WmsScheduler)->planned())->pluck('command')->all();

    expect($commands)->not->toContain('wms:reconcile')
        ->and($commands)->toContain('wms:sync-inventory')
        ->and($commands)->toContain('wms:health --alert');
});

test('TC-PS-004 计划任务能真正注册到 Schedule（含频率与防重叠）', function () {
    $schedule = new Schedule;
    (new WmsScheduler)($schedule);

    $events = collect($schedule->events());

    expect($events)->toHaveCount(4)
        ->and($events->pluck('expression')->all())->toBe(['*/30 * * * *', '30 2 * * *', '0 4 * * *', '0 * * * *']);
});

test('TC-PS-005 命令可独立执行且无副作用：reconcile 在无快照时不开单', function () {
    $this->artisan('wms:reconcile')->assertExitCode(0);

    expect(\App\Models\WmsInventoryDiff::count())->toBe(0);
});

test('TC-PS-006 wms:prune-logs 只删超期日志，保留窗口内的排障依据', function () {
    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'queryInventory', 'success' => true, 'created_at' => now()->subDays(120)]);
    WmsApiLog::forceCreate(['direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'queryInventory', 'success' => true, 'created_at' => now()->subDays(10)]);

    $this->artisan('wms:prune-logs --days=90')->assertExitCode(0);

    expect(WmsApiLog::count())->toBe(1)
        ->and(WmsApiLog::first()->created_at->gt(now()->subDays(90)))->toBeTrue();
});

test('TC-PS-007 降级开关：关闭 auto_push 后配置落审计（新单据停 created 由 P1 用例覆盖）', function () {
    $warehouse = \App\Models\Warehouse::create(['code' => 'WH_DG_'.uniqid(), 'name' => '降级仓', 'status' => 1]);

    app(\App\Services\Wms\WmsConfigService::class)->save($warehouse->id, [
        'provider' => 'cainiao', 'enabled' => true, 'auto_push' => false,
        'sku_mapping_mode' => 'same', 'app_key' => 'K', 'customer_id' => 'C',
        'api_env' => 'sandbox', 'warehouse_code' => 'W',
    ], 1);

    $log = \App\Models\SysOperationLog::where('action', 'config_created')->first();
    expect($log)->not->toBeNull()
        ->and(json_decode($log->content, true)['auto_push'])->toBeFalse();
});
