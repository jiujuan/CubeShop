<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Services\Order\OrderService;
use App\Services\Shipping\WaybillService;
use App\Support\Shipping\Kuaidi100WaybillChannel;
use App\Support\Shipping\MockWaybillChannel;
use App\Support\Shipping\NullWaybillChannel;
use App\Support\Shipping\WaybillRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
});

function waybillOrder(string $status = Order::STATUS_PENDING_SHIP): Order
{
    $user = createTestUser('wb'.uniqid());

    return Order::create([
        'order_no' => 'WB'.uniqid(),
        'user_id' => $user->id,
        'status' => $status,
        'total_amount' => '10.00',
        'freight_amount' => '0.00',
        'pay_amount' => '10.00',
        'address_snapshot' => [
            'contact_name' => '张三',
            'contact_phone' => '13800000000',
            'province' => '广东', 'city' => '深圳', 'district' => '南山', 'detail_address' => '科技园1号',
            'full_address' => '广东省深圳市南山区科技园1号',
        ],
    ]);
}

// ---------- 渠道单元 ----------
test('mock waybill channel issues deterministic tracking no', function () {
    $ch = new MockWaybillChannel();
    expect($ch->available())->toBeTrue();
    expect($ch->channelName())->toBe('mock');

    $req = new WaybillRequest('ORD1', 'SF', '张三', '13800000000', '广东深圳');
    $r1 = $ch->issue($req);
    $r2 = $ch->issue($req);
    expect($r1->success)->toBeTrue();
    expect($r1->trackingNo)->toStartWith('MOCK');
    expect($r1->trackingNo)->toBe($r2->trackingNo); // 确定性：同参数多次调用一致
    expect($r1->labelData)->not->toBeNull();
});

test('null waybill channel is unavailable', function () {
    $ch = new NullWaybillChannel();
    expect($ch->available())->toBeFalse();
    expect($ch->channelName())->toBe('null');
    expect($ch->issue(new WaybillRequest('O', 'SF', 'a', '1', 'x'))->success)->toBeFalse();
});

test('kuaidi100 waybill channel parses kuaidinum and requires key', function () {
    // 无密钥 → 不可用
    config(['services.waybill.key' => '', 'services.waybill.customer' => '']);
    expect((new Kuaidi100WaybillChannel())->available())->toBeFalse();

    // 有密钥 + 伪造响应
    config([
        'services.waybill.key' => 'k',
        'services.waybill.customer' => 'c',
        'services.waybill.order_url' => 'https://example.test/order',
    ]);
    Http::fake(fn () => Http::response([
        'result' => true,
        'data' => ['kuaidinum' => 'KF123456', 'printTemplate' => '<div>label</div>'],
    ]));

    $ch = new Kuaidi100WaybillChannel();
    expect($ch->available())->toBeTrue();
    $res = $ch->issue(new WaybillRequest('ORD9', 'SF', '张三', '13800000000', '广东深圳'));
    expect($res->success)->toBeTrue();
    expect($res->trackingNo)->toBe('KF123456');
    expect($res->labelData)->toBe('<div>label</div>');
});

// ---------- 服务编排 ----------
test('waybill service builds request from order snapshot', function () {
    $order = waybillOrder();
    $service = new WaybillService(new MockWaybillChannel());
    $res = $service->issueForOrder($order, 'SF');
    expect($res->success)->toBeTrue();
    expect($res->trackingNo)->toStartWith('MOCK');
});

// ---------- 订单发货写回 ----------
test('shipment with issueWaybill writes back mock tracking no', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = waybillOrder();
    $shipped = app(OrderService::class)
        ->shipForShipment($order, 'SF', '顺丰速运', 'UNUSED_MANUAL', issueWaybill: true);

    $shipping = Shipping::where('order_id', $order->id)->first();
    expect($shipping)->not->toBeNull();
    expect($shipping->tracking_no)->toStartWith('MOCK'); // 生成的单号覆盖入参
    expect($shipping->waybill_channel)->toBe('mock');
    expect($shipping->waybill_printed_at)->not->toBeNull();
    expect($shipped->tracking_no)->toBe($shipping->tracking_no); // 订单冗余双号同步
});

test('shipment follows channel availability by default (mock => auto issue)', function () {
    config(['services.waybill.channel' => 'mock']);
    $order = waybillOrder();
    app(OrderService::class)->shipForShipment($order, 'SF', '顺丰速运', 'IGNORED');

    $shipping = Shipping::where('order_id', $order->id)->first();
    expect($shipping->tracking_no)->toStartWith('MOCK');
    expect($shipping->waybill_channel)->toBe('mock');
});

test('shipment without issueWaybill keeps manual tracking no and null waybill', function () {
    config(['services.waybill.channel' => null]);
    $order = waybillOrder();
    app(OrderService::class)
        ->shipForShipment($order, 'SF', '顺丰速运', 'MANUAL123', issueWaybill: false);

    $shipping = Shipping::where('order_id', $order->id)->first();
    expect($shipping->tracking_no)->toBe('MANUAL123');
    expect($shipping->waybill_channel)->toBeNull();
    expect($shipping->waybill_printed_at)->toBeNull();
});

test('shipment follows channel availability by default (null => manual)', function () {
    config(['services.waybill.channel' => null]);
    $order = waybillOrder();
    app(OrderService::class)->shipForShipment($order, 'SF', '顺丰速运', 'MANUAL999');

    $shipping = Shipping::where('order_id', $order->id)->first();
    expect($shipping->tracking_no)->toBe('MANUAL999');
    expect($shipping->waybill_channel)->toBeNull();
});
