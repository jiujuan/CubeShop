<?php

use App\Models\ExpressCompany;
use App\Models\Order;
use App\Models\Shipping;
use App\Services\Order\BatchShipService;
use App\Services\Order\OrderService;
use App\Support\Shipping\Kuaidi100AutoNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * V1.1 三期：手机号冗余与智能识别集成
 *
 * 覆盖：① 发货（含 WMS/批量共用的唯一入口）把收货人手机号写入 shippings；
 *       ② 历史运单 phone 为空时回落到 address_snapshot；
 *       ③ 批量校验识别结果与填写不一致 → warnings 提示但不阻断；
 *       ④ 识别不可用时批量校验零影响。
 */

function kd100ShipCompanies(): void
{
    foreach ([
        ['YTO', '圆通速递', 'yuantong'],
        ['ZTO', '中通快递', 'zhongtong'],
        ['SF', '顺丰速运', 'shunfeng'],
    ] as [$code, $name, $channel]) {
        ExpressCompany::forceCreate([
            'code' => $code,
            'name' => $name,
            'channel_code' => $channel,
            'sort' => 1,
            'status' => 1,
        ]);
    }
    Kuaidi100AutoNumber::flushCache();
}

/**
 * 待发货订单（address_snapshot 用真实业务字段名 contact_name/contact_phone）
 */
function kd100PendingOrder(string $phone = '13800001111'): Order
{
    $user = createTestUser('kd100'.uniqid());

    return Order::create([
        'order_no' => 'CSKD'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => [
            'contact_name' => '张三',
            'contact_phone' => $phone,
            'province' => '广东省',
            'city' => '深圳市',
            'district' => '南山区',
            'detail_address' => '科技路 1 号',
        ],
    ]);
}

beforeEach(function () {
    seedRoles();
    kd100ShipCompanies();
});

test('TC-KD100-14 发货把收货人手机号冗余进运单', function () {
    $order = kd100PendingOrder('13800001111');

    $shipped = app(OrderService::class)->shipForShipment($order, 'SF', '顺丰速运', 'SF12345678');

    $shipping = Shipping::where('order_id', $shipped->id)->first();

    expect($shipping)->not->toBeNull()
        ->and($shipping->phone)->toBe('13800001111')
        // 顺丰轨迹查询必填手机号，冗余后可直接用于查询
        ->and($shipping->resolvePhone())->toBe('13800001111');
});

test('TC-KD100-15 历史运单 phone 为空时回落到收货快照', function () {
    $order = kd100PendingOrder('13900002222');
    $shipped = app(OrderService::class)->shipForShipment($order, 'YTO', '圆通速递', 'YT12345678');
    $shipping = Shipping::where('order_id', $shipped->id)->first();

    // 模拟 000110 迁移前的历史数据
    $shipping->update(['phone' => null]);

    expect($shipping->fresh()->phone)->toBeNull()
        ->and($shipping->fresh()->resolvePhone())->toBe('13900002222');
});

test('TC-KD100-16 收货快照也无手机号时返回 null', function () {
    $order = kd100PendingOrder('');
    $shipped = app(OrderService::class)->shipForShipment($order, 'YTO', '圆通速递', 'YT99999999');
    $shipping = Shipping::where('order_id', $shipped->id)->first();

    expect($shipping->phone)->toBeNull()
        ->and($shipping->resolvePhone())->toBeNull();
});

test('TC-KD100-17 批量校验识别不一致产生 warnings 但不阻断', function () {
    config([
        'services.shipping.key' => 'TESTKEY',
        'services.shipping.autonumber_enabled' => true,
        'services.shipping.autonumber_batch_limit' => 100,
    ]);

    $order = kd100PendingOrder();

    // 单号实际属于圆通，但表格里填成了中通
    Http::fake(['*' => Http::response([
        ['comCode' => 'yuantong', 'name' => '圆通速递'],
    ], 200)]);

    $result = app(BatchShipService::class)->validateRows([
        [$order->order_no, 'ZTO', 'YT12345678'],
    ]);

    expect($result['failed'])->toBe([])
        // 不阻断：仍进入 valid 可正常发货
        ->and($result['valid'])->toHaveCount(1)
        ->and($result['valid'][0]['company_code'])->toBe('ZTO')
        // 仅提示复核
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['filled'])->toBe('ZTO')
        ->and($result['warnings'][0]['detected'])->toBe('YTO')
        ->and($result['warnings'][0]['detected_name'])->toBe('圆通速递')
        ->and($result['warnings'][0]['row'])->toBe(2); // Excel 行号（首行为表头）
});

test('TC-KD100-18 识别一致时不产生 warnings', function () {
    config([
        'services.shipping.key' => 'TESTKEY',
        'services.shipping.autonumber_enabled' => true,
    ]);

    $order = kd100PendingOrder();

    Http::fake(['*' => Http::response([
        ['comCode' => 'zhongtong', 'name' => '中通快递'],
    ], 200)]);

    $result = app(BatchShipService::class)->validateRows([
        [$order->order_no, 'ZTO', 'ZTO12345678'],
    ]);

    expect($result['warnings'])->toBe([])
        ->and($result['valid'])->toHaveCount(1);
});

test('TC-KD100-19 识别不可用时批量校验零影响', function () {
    config(['services.shipping.key' => null]);

    $order = kd100PendingOrder();

    Http::fake(['*' => Http::response([], 200)]);

    $result = app(BatchShipService::class)->validateRows([
        [$order->order_no, 'ZTO', 'ZTO12345678'],
    ]);

    expect($result['warnings'])->toBe([])
        ->and($result['valid'])->toHaveCount(1);

    Http::assertNothingSent();
});

test('TC-KD100-20 识别限额生效，超出部分跳过识别', function () {
    config([
        'services.shipping.key' => 'TESTKEY',
        'services.shipping.autonumber_enabled' => true,
        'services.shipping.autonumber_batch_limit' => 1,
    ]);

    $o1 = kd100PendingOrder();
    $o2 = kd100PendingOrder();

    Http::fake(['*' => Http::response([['comCode' => 'yuantong', 'name' => '圆通速递']], 200)]);

    app(BatchShipService::class)->validateRows([
        [$o1->order_no, 'ZTO', 'YT11111111'],
        [$o2->order_no, 'ZTO', 'YT22222222'],
    ]);

    // 限额 1：只识别第一行，第二行跳过
    Http::assertSentCount(1);
});
