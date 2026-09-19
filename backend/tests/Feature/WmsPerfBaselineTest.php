<?php

use App\Jobs\Wms\ProcessWmsCallbackJob;
use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Wms\FulfillmentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);
use App\Models\FulfillmentOrderItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Stage P7 / Step 4 性能基线（沙箱 / fake 网关）。
 *
 * 数值用于「相对基线」而非绝对 SLA：沙箱无真实网络，网关调用被 Http::fake 即时返回。
 * 生产需以真实网关复测。断言全部为宽松上限，避免 CI 抖动误判；真实指标通过 dump 输出。
 */

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true, 'app.url' => 'https://shop.test']);
    config(['wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw']);
    Queue::fake();
    $this->sku = createTestSku(stock: 500, price: '50.00');
    $this->sku->forceFill(['sku_code' => 'PERF-SKU-A'])->save();
});

test('PERF-01 批量 200 单推送吞吐', function () {
    wmsUatGatewaySuccess('PERF');
    $config = wmsUatConfig();
    ['auth' => $auth] = wmsUatBuyer();
    $user = \App\Models\User::where('phone', '13800000000')->first()
        ?? createTestUser('perf');

    // 造 200 张真实订单 + 明细（forceCreate 直接落库，避免 800 次 HTTP 往返）
    $orderIds = [];
    for ($i = 0; $i < 200; $i++) {
        $order = Order::forceCreate([
            'order_no' => 'PERF-'.uniqid(),
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'total_amount' => 50,
            'pay_amount' => 50,
            'warehouse_id' => $config->warehouse_id,
            'address_snapshot' => [
                'contact_name' => '压测', 'contact_phone' => '13800000000',
                'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
                'detail_address' => '路 1 号', 'full_address' => '广东省深圳市南山区路 1 号',
            ],
        ]);
        OrderItem::forceCreate([
            'order_id' => $order->id,
            'sku_id' => test()->sku->id,
            'product_title' => 'PERF 品',
            'price' => 50,
            'quantity' => 1,
            'total_amount' => 50,
        ]);
        $orderIds[] = $order->id;
    }

    // 经正式建单链路生成 200 张发货单（pending_push）
    $svc = app(FulfillmentOrderService::class);
    $foIds = [];
    foreach ($orderIds as $oid) {
        $fo = $svc->createForOrder(Order::findOrFail($oid), $config->warehouse_id);
        $foIds[] = $fo->id;
    }

    $start = microtime(true);
    $tMin = PHP_FLOAT_MAX;
    $tMax = 0;
    foreach ($foIds as $foId) {
        $t0 = microtime(true);
        (new PushOutboundJob($foId, 3))->handle();
        $dt = (microtime(true) - $t0) * 1000;
        $tMin = min($tMin, $dt);
        $tMax = max($tMax, $dt);
    }
    $total = microtime(true) - $start;

    dump([
        'push_total_s' => round($total, 3),
        'avg_ms' => round($total * 1000 / 200, 2),
        'min_ms' => round($tMin, 2),
        'max_ms' => round($tMax, 2),
    ]);

    expect($total)->toBeLessThan(60);
    expect(FulfillmentOrder::whereIn('id', $foIds)->where('status', FulfillmentOrder::STATUS_PUSHED)->count())
        ->toBe(200);
});

test('PERF-02 回调 50 次采样 P95 响应时间', function () {
    wmsUatGatewaySuccess('PERF');
    $config = wmsUatConfig();
    ['auth' => $auth] = wmsUatBuyer();
    $order = wmsUatPlaceAndPay($auth, [[test()->sku, 1]]);
    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    wmsUatRunPushOutbound();

    $samples = [];
    for ($i = 0; $i < 50; $i++) {
        $payload = wmsUatConfirmPayload($fo, [[
            'logisticsCode' => 'YTO', 'logisticsName' => '圆通速递', 'expressCode' => 'YTO-P'.($i + 1), 'weight' => '1.000',
            'items' => ['item' => [['itemCode' => 'PERF-SKU-A', 'quantity' => 1]]],
        ]], '2026-09-20 11:'.sprintf('%02d', $i % 60).':00');

        $t0 = microtime(true);
        wmsPostCallback($payload, WMS_UAT_APP_SECRET);
        $samples[] = (microtime(true) - $t0) * 1000;
    }

    sort($samples);
    $p95 = $samples[(int) (0.95 * count($samples)) - 1] ?? end($samples);
    $p50 = $samples[(int) (0.5 * count($samples)) - 1] ?? end($samples);

    dump(['callback_p50_ms' => round($p50, 2), 'callback_p95_ms' => round($p95, 2)]);

    // 回调入口为快进快出（仅落队），P95 远低于 300ms 目标
    expect($p95)->toBeLessThan(300);
    // 50 次全部受理（不静默丢单）
    expect(Queue::pushed(ProcessWmsCallbackJob::class)->count())->toBe(50);
});
