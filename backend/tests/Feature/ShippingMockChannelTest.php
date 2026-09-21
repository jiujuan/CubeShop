<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Services\Shipping\TracePullService;
use App\Support\Shipping\MockChannel;
use App\Support\Shipping\ShippingChannelInterface;
use App\Support\Shipping\TraceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 三期回归：MockChannel 轨迹时间必须确定性
 *
 * 曾经的 bug：MockChannel 用 now() 生成轨迹时间，而 TracePullService 的去重键是
 * `occurred_at|context`，时间戳每次都变 → 去重永不命中 → 每 30 分钟插入一批内容相同的
 * 重复轨迹（用户端订单页物流信息「满屏」）。此处锁定「同参数多次调用结果完全一致」。
 */

beforeEach(function () {
    seedRoles();
    app()->instance(ShippingChannelInterface::class, new MockChannel);
});

/** 建一条 mock 运单 */
function mockChannelShipping(string $trackingNo = 'SFMOCK0001'): Shipping
{
    $user = createTestUser('mock'.uniqid());
    $order = Order::create([
        'order_no' => 'CSMOCK'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_SHIPPED,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => ['name' => '测试', 'contact_phone' => '13800000000'],
    ]);

    return Shipping::create([
        'order_id' => $order->id,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => $trackingNo,
        'trace_status' => Shipping::TRACE_PENDING,
        'shipped_at' => now()->subDay(),
    ]);
}

test('TC-MOCK-01 同一运单多次查询返回完全相同的轨迹时间', function () {
    $channel = new MockChannel;

    $first = $channel->query('SF', 'SFMOCK0001');
    $second = $channel->query('SF', 'SFMOCK0001');

    expect($first->traces)->toEqual($second->traces)
        ->and($first->traces)->toHaveCount(3);

    // 关键：时间戳逐条一致（曾用 now() 导致每次不同）
    expect(array_column($first->traces, 'occurred_at'))
        ->toBe(array_column($second->traces, 'occurred_at'));
});

test('TC-MOCK-02 重复拉取不产生重复轨迹（去重键命中）', function () {
    $shipping = mockChannelShipping();
    $service = app(TracePullService::class);

    expect($service->pull($shipping))->toBe(TracePullService::RESULT_PULLED)
        ->and($shipping->traces()->count())->toBe(3);

    // 第二次：时间未变 → 去重命中 → 不新增
    $shipping->refresh();
    expect($service->pull($shipping))->toBe(TracePullService::RESULT_PULLED)
        ->and($shipping->traces()->count())->toBe(3);

    // 第三次同样不增长（模拟每 30 分钟一次调度连跑）
    $shipping->refresh();
    $service->pull($shipping);
    expect(ShippingTrace::where('shipping_id', $shipping->id)->count())->toBe(3);
});

test('TC-MOCK-03 单号以 OK 结尾返回签收轨迹并回填 delivered_at', function () {
    $shipping = mockChannelShipping('SFMOCK0002OK');

    $service = app(TracePullService::class);
    $service->pull($shipping);
    $shipping->refresh();

    expect($shipping->traces()->count())->toBe(4)
        ->and($shipping->trace_status)->toBe(Shipping::TRACE_DELIVERED)
        ->and($shipping->delivered_at)->not->toBeNull();

    // 末条（最新）为签收阶段
    $result = (new MockChannel)->query('SF', 'SFMOCK0002OK');
    expect(array_column($result->traces, 'stage'))->toBe([
        TraceStage::PICKUP,
        TraceStage::IN_TRANSIT,
        TraceStage::DELIVERING,
        TraceStage::DELIVERED,
    ]);
});

test('TC-MOCK-04 不同运单号得到不同的轨迹内容（种子生效）', function () {
    $a = (new MockChannel)->query('SF', 'AAAA0001');
    $b = (new MockChannel)->query('YTO', 'BBBB0002');

    // 城市/快递员由 crc32 种子决定，内容应不同
    expect($a->traces[0]['context'])->not->toBe($b->traces[0]['context']);
});
