<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 三期：重复轨迹清理迁移（000111）
 *
 * 背景：MockChannel 曾用 now() 生成轨迹时间 → 去重键不命中 → 每 30 分钟一批重复轨迹。
 * 迁移按 (shipping_id, context, occurred_at 日期) 保留 id 最小的一条，跨天同名不误删。
 */

/** 建运单 */
function pruneShipping(): Shipping
{
    $user = createTestUser('prune'.uniqid());
    $order = Order::create([
        'order_no' => 'CSPRUNE'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '10.00',
        'freight_amount' => '0.00',
        'pay_amount' => '10.00',
        'address_snapshot' => ['name' => '测试', 'contact_phone' => '13800000000'],
    ]);

    return Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'SFPRUNE'.uniqid(),
        'trace_status' => Shipping::TRACE_IN_TRANSIT,
        'shipped_at' => now()->subDay(),
    ]);
}

/** 执行清理迁移（幂等，可重复调用） */
function runPruneMigration(): void
{
    $migration = require database_path('migrations/2026_09_22_000111_prune_duplicate_shipping_traces.php');
    $migration->up();
}

beforeEach(function () {
    seedRoles();
});

test('TC-PRUNE-01 同一天同一描述只保留最早一条', function () {
    $shipping = pruneShipping();

    // 模拟旧 bug：同一条轨迹被反复插入，时间差几秒
    foreach (['09:00:00', '09:30:00', '10:00:00'] as $time) {
        ShippingTrace::create([
            'shipping_id' => $shipping->id,
            'context' => '快件已揽收',
            'occurred_at' => now()->startOfDay()->setTimeFromTimeString($time),
        ]);
    }

    expect($shipping->traces()->count())->toBe(3);

    runPruneMigration();

    expect($shipping->traces()->count())->toBe(1)
        ->and($shipping->traces()->first()->occurred_at->format('H:i:s'))->toBe('09:00:00');
});

test('TC-PRUNE-02 跨天同名轨迹不误删', function () {
    $shipping = pruneShipping();

    ShippingTrace::create([
        'shipping_id' => $shipping->id,
        'context' => '快件已到达【深圳市】中转中心',
        'occurred_at' => now()->subDays(2)->startOfDay()->setTime(9, 0),
    ]);
    ShippingTrace::create([
        'shipping_id' => $shipping->id,
        'context' => '快件已到达【深圳市】中转中心',
        'occurred_at' => now()->subDay()->startOfDay()->setTime(9, 0),
    ]);

    runPruneMigration();

    // 分属两天，属于真实业务上的两次中转，保留
    expect($shipping->traces()->count())->toBe(2);
});

test('TC-PRUNE-03 不同运单互不干扰，且迁移幂等', function () {
    $a = pruneShipping();
    $b = pruneShipping();

    ShippingTrace::create(['shipping_id' => $a->id, 'context' => '快件已揽收', 'occurred_at' => now()->startOfDay()->setTime(9, 0)]);
    ShippingTrace::create(['shipping_id' => $a->id, 'context' => '快件已揽收', 'occurred_at' => now()->startOfDay()->setTime(11, 0)]);
    ShippingTrace::create(['shipping_id' => $b->id, 'context' => '快件已签收', 'occurred_at' => now()->startOfDay()->setTime(15, 0)]);

    runPruneMigration();

    expect($a->traces()->count())->toBe(1)
        ->and($b->traces()->count())->toBe(1);

    // 重复执行不再删除任何数据
    runPruneMigration();
    expect(ShippingTrace::count())->toBe(2);
});
