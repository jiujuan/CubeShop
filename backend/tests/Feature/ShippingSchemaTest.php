<?php

use App\Models\ExpressCompany;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-042（E03/F07）：物流四表与快递公司字典种子
 *
 * 覆盖：模型关系（订单-发货-轨迹）、字典种子完整性与启停过滤、冗余双号可写。
 * 双库迁移由 pest（SQLite）与 pest -c phpunit.pgsql.xml（PostgreSQL）共同验证。
 */
beforeEach(function () {
    seedRoles();
});

test('TC-SHP-042-01 订单-发货-轨迹模型关系正确', function () {
    $user = createTestUser('shp042');
    $order = Order::create([
        'order_no' => 'CS042'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000'],
        'express_company' => '顺丰速运',
        'tracking_no' => 'SF042TEST01',
    ]);

    $shipping = Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'SF042TEST01',
        'trace_status' => Shipping::TRACE_IN_TRANSIT,
        'shipped_at' => now(),
    ]);

    $traceNew = ShippingTrace::create([
        'shipping_id' => $shipping->id,
        'context' => '快件已到达深圳转运中心',
        'occurred_at' => now()->subHour(),
        'raw' => ['context' => '到达', 'time' => now()->subHour()->toDateTimeString()],
    ]);
    $traceOld = ShippingTrace::create([
        'shipping_id' => $shipping->id,
        'context' => '快件已揽收',
        'occurred_at' => now()->subDay(),
    ]);

    // 订单 → 发货
    expect($order->shipping()->count())->toBe(1)
        ->and($order->shipping->first()->company_code)->toBe('SF')
        // 发货 → 轨迹（倒序：最新在前）
        ->and($shipping->traces()->count())->toBe(2)
        ->and($shipping->traces->first()->id)->toBe($traceNew->id)
        ->and($shipping->traces->first()->context)->toContain('转运中心')
        // 轨迹 → 发货
        ->and($traceOld->shipping->order_id)->toBe($order->id)
        // raw cast
        ->and($traceNew->raw)->toBeArray();
});

test('TC-SHP-042-02 快递公司字典种子 8 家完整且可按启用过滤', function () {
    // 直接跑种子（RefreshDatabase 清空后重灌）
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    expect(ExpressCompany::count())->toBe(8)
        ->and(ExpressCompany::where('code', 'SF')->value('name'))->toBe('顺丰速运')
        ->and(ExpressCompany::where('code', 'ZTO')->value('name'))->toBe('中通快递')
        ->and(ExpressCompany::where('code', 'JT')->value('name'))->toBe('极兔速递')
        // 全部启用且按 sort 排序
        ->and(ExpressCompany::enabled()->pluck('code')->all())
        ->toBe(['SF', 'ZTO', 'YTO', 'YD', 'STO', 'JD', 'EMS', 'JT']);

    // 停用后被 enabled 过滤
    ExpressCompany::where('code', 'JT')->update(['status' => 0]);
    expect(ExpressCompany::enabled()->pluck('code'))->not->toContain('JT')
        ->and(ExpressCompany::count())->toBe(8);
});

test('TC-SHP-042-03 冗余双号列可写且同公司运单号唯一', function () {
    $user = createTestUser('shp042b');
    $order = Order::create([
        'order_no' => 'CS042B'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '50.00',
        'freight_amount' => '10.00',
        'pay_amount' => '60.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000'],
        'express_company' => '中通快递',
        'tracking_no' => 'ZTO042UNIQ01',
    ]);

    expect($order->fresh()->tracking_no)->toBe('ZTO042UNIQ01')
        ->and($order->fresh()->express_company)->toBe('中通快递');

    Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'ZTO',
        'company_name' => '中通快递',
        'tracking_no' => 'ZTO042UNIQ01',
        'trace_status' => Shipping::TRACE_PENDING,
        'shipped_at' => now(),
    ]);

    // 同公司同单号再挂一单 → 唯一约束拒绝
    $order2 = Order::create([
        'order_no' => 'CS042C'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PAID,
        'total_amount' => '50.00',
        'freight_amount' => '10.00',
        'pay_amount' => '60.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000'],
    ]);

    expect(fn () => Shipping::create([
        'order_id' => $order2->id,
        'company_code' => 'ZTO',
        'company_name' => '中通快递',
        'tracking_no' => 'ZTO042UNIQ01',
        'shipped_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
