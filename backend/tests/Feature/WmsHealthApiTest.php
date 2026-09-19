<?php

use App\Models\FulfillmentOrder;
use App\Models\ReturnInboundOrder;
use App\Models\SysOperationLog;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Models\Warehouse;
use App\Services\Notification\NotificationService;
use App\Services\Wms\WmsHealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * WMS 健康巡检（WMS 计划 P5 / §4.1）
 *
 * 覆盖：接口结构、缺配置 warning、单据类异常统计准确、失败率、401/403、
 * 命令退出码，以及「有问题才告警」的双通道落地。
 */

beforeEach(function () {
    seedRoles();

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

function p5EnabledConfig(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_H_'.uniqid(), 'name' => '巡检仓', 'status' => 1]);
    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'K', 'customer_id' => 'C', 'api_env' => 'sandbox', 'warehouse_code' => 'W',
        'callback_token' => 't'.bin2hex(random_bytes(8)),
    ], $attrs));
    $config->app_secret = 'SECRET';
    $config->save();

    return $config->refresh();
}

test('TC-PH-001 健康接口返回结构完整（六项检查 + 汇总）', function () {
    p5EnabledConfig();

    $data = $this->getJson('/api/admin/wms/health', $this->adminAuth)->assertOk()->json('data');

    expect($data)->toHaveKeys(['checked_at', 'healthy', 'summary', 'checks'])
        ->and($data['checks'])->toHaveCount(6)
        ->and(collect($data['checks'])->pluck('key')->all())
        ->toBe(['config', 'pushing_timeout', 'push_failed', 'exception', 'fail_rate', 'queue_backlog']);
});

test('TC-PH-002 未配置任何 WMS → config 项 warning 且整体不健康', function () {
    $data = $this->getJson('/api/admin/wms/health', $this->adminAuth)->assertOk()->json('data');

    expect($data['healthy'])->toBeFalse()
        ->and(collect($data['checks'])->firstWhere('key', 'config')['status'])->toBe(WmsHealthCheckService::STATUS_WARNING);
});

test('TC-PH-003 已启用但缺凭证 → config 项 error', function () {
    p5EnabledConfig(['app_key' => '']);

    $report = app(WmsHealthCheckService::class)->collect();

    expect(collect($report['checks'])->firstWhere('key', 'config')['status'])->toBe(WmsHealthCheckService::STATUS_ERROR);
});

/** 造真实订单（单据外键必须指向真实实体，统计类测试也不能绕过约束） */
function p5Order(): \App\Models\Order
{
    $user = createTestUser('p5o'.substr(uniqid(), -6));

    return \App\Models\Order::forceCreate([
        'order_no' => 'P5O'.substr(uniqid(), -8),
        'user_id' => $user->id,
        'status' => 'paid',
        'total_amount' => '10.00',
        'pay_amount' => '10.00',
        'address_snapshot' => ['contact_name' => '张三', 'contact_phone' => '13800000000', 'detail_address' => '测试地址'],
    ]);
}

/** 造真实退货退款单（退货入库单的外键） */
function p5Refund(\App\Models\Order $order): \App\Models\Refund
{
    return \App\Models\Refund::forceCreate([
        'refund_no' => 'RF'.substr(uniqid(), -8),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $order->user_id,
        'amount' => '10.00',
        'status' => 'approved',
        'type' => \App\Models\Refund::TYPE_RETURN_REFUND,
    ]);
}

/**
 * 造一张发货单（只关心状态与时间戳）。
 * `fulfillment_orders.order_id` 唯一（一单一发货单），故每张单据自带一条订单。
 */
function p5Outbound(string $status, string $no, int $warehouseId, ?\DateTimeInterface $at = null): void
{
    $order = p5Order();

    FulfillmentOrder::forceCreate([
        'order_id' => $order->id, 'order_no' => $order->order_no, 'outbound_no' => $no,
        'warehouse_id' => $warehouseId, 'provider' => 'cainiao', 'status' => $status,
        'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
    ]);
}

/** 造一张退货入库单（只关心状态与时间戳） */
function p5Return(string $status, string $no, int $warehouseId): void
{
    $order = p5Order();
    $refund = p5Refund($order);

    ReturnInboundOrder::forceCreate([
        'refund_id' => $refund->id, 'refund_no' => $refund->refund_no,
        'order_id' => $order->id, 'order_no' => $order->order_no,
        'inbound_no' => $no, 'warehouse_id' => $warehouseId, 'provider' => 'cainiao',
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('TC-PH-004 卡死的 pushing 单据被统计（超过 30 分钟阈值）', function () {
    $config = p5EnabledConfig();

    p5Outbound(FulfillmentOrder::STATUS_PUSHING, 'FO-STUCK', (int) $config->warehouse_id, now()->subMinutes(40));
    // 刚推送的（5 分钟前）不该被算进去
    p5Outbound(FulfillmentOrder::STATUS_PUSHING, 'FO-FRESH', (int) $config->warehouse_id, now()->subMinutes(5));

    $check = collect(app(WmsHealthCheckService::class)->collect()['checks'])->firstWhere('key', 'pushing_timeout');

    expect($check['status'])->toBe(WmsHealthCheckService::STATUS_WARNING)
        ->and($check['value']['outbound'])->toBe(1);
});

test('TC-PH-005 推送失败与异常单据（发货 + 退货）汇总准确', function () {
    $config = p5EnabledConfig();
    $warehouseId = (int) $config->warehouse_id;

    p5Outbound(FulfillmentOrder::STATUS_PUSH_FAILED, 'FO-FAIL', $warehouseId);
    p5Outbound(FulfillmentOrder::STATUS_EXCEPTION, 'FO-EXC', $warehouseId);
    p5Return(ReturnInboundOrder::STATUS_PUSH_FAILED, 'RI-FAIL', $warehouseId);

    $report = app(WmsHealthCheckService::class)->collect();

    expect(collect($report['checks'])->firstWhere('key', 'push_failed')['value']['total'])->toBe(2)
        ->and(collect($report['checks'])->firstWhere('key', 'exception')['value']['total'])->toBe(1)
        ->and($report['healthy'])->toBeFalse();
});

test('TC-PH-006 接口失败率超阈值告警（近 24 小时窗口）', function () {
    p5EnabledConfig();
    foreach (range(1, 4) as $i) {
        WmsApiLog::forceCreate([
            'direction' => 'outbound', 'provider' => 'cainiao', 'api_name' => 'queryInventory',
            'success' => $i <= 1, 'created_at' => now()->subHours(2),
        ]);
    }

    $check = collect(app(WmsHealthCheckService::class)->collect()['checks'])->firstWhere('key', 'fail_rate');

    expect($check['value']['rate'])->toBe(0.75)
        ->and($check['status'])->toBe(WmsHealthCheckService::STATUS_WARNING);
});

test('TC-PH-007 全部正常 → healthy=true，不推告警', function () {
    p5EnabledConfig();
    Notification::fake();

    $report = app(WmsHealthCheckService::class)->collectAndAlert();

    expect($report['healthy'])->toBeTrue()
        ->and(SysOperationLog::where('action', 'health_alert')->count())->toBe(0);
});

test('TC-PH-008 有问题时 collectAndAlert 落审计 + 站内信（双通道）', function () {
    p5EnabledConfig(['enabled' => false]); // 无启用配置 → 触发 warning
    Notification::fake();

    app(WmsHealthCheckService::class)->collectAndAlert();

    expect(SysOperationLog::where('action', 'health_alert')->count())->toBe(1);
    // 告警投给 wms.order.manage 持有者（admin + operator 共 2 条，同 P3 口径）
    expect(\App\Models\Notification::where('type', NotificationService::TYPE_WMS_ALERT)->count())->toBe(2);
});

test('TC-PH-009 未登录 / 无权限访问健康接口 → 401 / 403', function () {
    $this->getJson('/api/admin/wms/health')->assertStatus(401);

    $user = createTestUser('p5noperm');
    $this->getJson('/api/admin/wms/health', ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
        ->assertStatus(403);
});

test('TC-PH-010 wms:health 命令退出码反映健康状态', function () {
    p5EnabledConfig();

    $this->artisan('wms:health')->assertExitCode(0);

    WmsConfig::query()->update(['enabled' => false]);
    $this->artisan('wms:health')->assertExitCode(1);
});
