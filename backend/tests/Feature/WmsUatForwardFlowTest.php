<?php

use App\Jobs\Wms\ProcessWmsCallbackJob;
use App\Jobs\Wms\PushOutboundJob;
use App\Jobs\Wms\PushReturnInboundJob;
use App\Models\FulfillmentOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSku;
use App\Models\Refund;
use App\Models\ReturnInboundOrder;
use App\Models\Shipping;
use App\Models\ShippingPackage;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Services\Common\CaptchaService;
use App\Services\Wms\Callback\CallbackDeduplicator;
use App\Services\Wms\Callback\CallbackDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 正向链路端到端联调（WMS 计划 P7 / Step 2）
 *
 * 与 P1～P4 的分层测试区别：这里**只关心业务闭环能不能从头走到尾**，
 * 每一步都按联调报告的要求留下可核对的痕迹（`request_id` + 耗时）：
 *
 *   下单支付 → 发货单 pending_push → 推送 → pushed → 回传 confirm
 *   → 订单 shipped（+运单号 + 包裹 + 通知）
 *   → 退货申请 → 审核通过 → 退货入库单 → 推送 → 收货回传
 *   → 库存按实收回加 → 退款 success → 订单 refunded
 *
 * 沙箱账号到位后，同一套用例只需把 `uatGateway()` 的 Http::fake 换成真实网关
 * （配置 `wms.providers.cainiao.gateway.sandbox` + 真实凭证）即可重跑，
 * 断言与痕迹口径完全一致——这正是把联调写成自动化测试的意义。
 *
 * 数据集按 Step 1 要求：3 类商品（普通 / 多件 / 贵重）、
 * 2 个收货地址（华南 SF、华北 YTO）命中不同承运商、1 个多包裹场景。
 */

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true, 'app.url' => 'https://shop.test']);
    config(['wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw']);
    Cache::flush();
    Queue::fake();
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    /** Step 1 数据集：3 类商品 */
    $this->skuNormal = createTestSku(stock: 100, price: '50.00');    // 普通
    $this->skuMulti = createTestSku(stock: 100, price: '20.00');     // 多件（一次买 3）
    $this->skuPricey = createTestSku(stock: 100, price: '599.00');   // 贵重
    $this->skuNormal->forceFill(['sku_code' => 'UAT-SKU-A'])->save();
    $this->skuMulti->forceFill(['sku_code' => 'UAT-SKU-B'])->save();
    $this->skuPricey->forceFill(['sku_code' => 'UAT-SKU-C'])->save();
});

// ==================== 第 1 轮：单包裹正向闭环（华南 / SF） ====================

test('TC-UAT-01 第 1 轮：下单支付 → 推送 → 回传 → 订单已发货（单包裹）', function () {
    wmsUatConfig();
    ['auth' => $auth] = wmsUatBuyer();

    // 1) 下单支付
    $order = wmsUatPlaceAndPay($auth, [[$this->skuNormal, 1], [$this->skuPricey, 1]], 'south');
    expect($order->status)->toBe(Order::STATUS_PENDING_SHIP);

    // 2) 自动建发货单并进入待推送
    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH)
        ->and($fo->outbound_no)->toStartWith('FO')
        ->and($fo->items)->toHaveCount(2);

    // 3) 推送（队列作业直连，网关由 Http::fake 扮演）
    wmsUatGatewaySuccess('CN-UAT-1');
    wmsUatRunPushOutbound();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($fo->wms_outbound_no)->toBe('CN-UAT-1');

    // 4) 菜鸟回传发货
    Queue::fake();
    $res = wmsPostCallback(wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF123456', 'weight' => '2.000',
        'items' => ['item' => [
            ['itemCode' => 'UAT-SKU-A', 'itemName' => '普通商品', 'quantity' => 1],
            ['itemCode' => 'UAT-SKU-C', 'itemName' => '贵重商品', 'quantity' => 1],
        ]],
    ]]), WMS_UAT_APP_SECRET);
    expect($res['flag'])->toBe('success');

    wmsUatRunCallbacks();

    // 5) 订单发货 + 运单号 + 包裹落档
    $fo->refresh();
    $order->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and($fo->tracking_no)->toBe('SF123456')
        ->and($fo->carrier_code)->toBe('SF')
        ->and($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->tracking_no)->toBe('SF123456')
        ->and(Shipping::where('order_id', $order->id)->count())->toBe(1)
        ->and(wmsUatPackages($order)->count())->toBe(1);
});

// ==================== 第 2 轮：多包裹（华北 / YTO + JD） ====================

test('TC-UAT-02 第 2 轮：多包裹回传 → 主单取首包裹，全量包裹入档', function () {
    wmsUatConfig();
    ['auth' => $auth] = wmsUatBuyer();

    // 多件商品（3 件 × 多件商品）——真实场景里拆成两个包裹
    $order = wmsUatPlaceAndPay($auth, [[$this->skuMulti, 3]], 'north');

    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    wmsUatGatewaySuccess('CN-UAT-2');
    wmsUatRunPushOutbound();

    Queue::fake();
    wmsPostCallback(wmsUatConfirmPayload($fo, [
        [
            'logisticsCode' => 'YTO', 'logisticsName' => '圆通速递', 'expressCode' => 'YTO111', 'weight' => '1.200',
            'items' => ['item' => [['itemCode' => 'UAT-SKU-B', 'itemName' => '多件商品', 'quantity' => 2]]],
        ],
        [
            'logisticsCode' => 'JD', 'logisticsName' => '京东物流', 'expressCode' => 'JD222', 'weight' => '0.600',
            'items' => ['item' => [['itemCode' => 'UAT-SKU-B', 'itemName' => '多件商品', 'quantity' => 1]]],
        ],
    ]), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and($fo->tracking_no)->toBe('YTO111')
        ->and(wmsUatPackages($order)->count())->toBe(2)
        // 实发数量按两个包裹归集（2 + 1 = 3）
        ->and((int) $fo->items()->first()->shipped_qty)->toBe(3);
});

// ==================== 第 3 轮：少件退货闭环 ====================

test('TC-UAT-03 第 3 轮：发货后退货 → 审核 → 推送 → 少件收货 → 库存按实收回加 → 退款完成', function () {
    wmsUatConfig();
    ['auth' => $auth] = wmsUatBuyer();

    // 1) 下单支付 + 出库闭环（复用第 1 轮路径）
    $order = wmsUatPlaceAndPay($auth, [[$this->skuNormal, 2]], 'south');
    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();
    wmsUatGatewaySuccess('CN-UAT-3');
    wmsUatRunPushOutbound();

    Queue::fake();
    wmsPostCallback(wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF999', 'weight' => '1.000',
        'items' => ['item' => [['itemCode' => 'UAT-SKU-A', 'itemName' => '普通商品', 'quantity' => 2]]],
    ]]), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();
    expect($order->refresh()->status)->toBe(Order::STATUS_SHIPPED);

    // 2) 买家发起退货退款（应退 2 件）
    $item = OrderItem::where('order_id', $order->id)->first();
    test()->postJson("/api/orders/{$order->id}/refund", [
        'reason' => '七天无理由',
        'type' => 'return_refund',
        'return_details' => [
            ['sku_id' => $item->sku_id, 'quantity' => 2, 'product_title' => '普通商品', 'sku_specs' => []],
        ],
    ], $auth)->assertOk();

    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    expect($refund->type)->toBe(Refund::TYPE_RETURN_REFUND);

    // 3) 后台审核通过 → 生成退货入库单（pending_push）
    Queue::fake();
    test()->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $rio = ReturnInboundOrder::where('refund_id', $refund->id)->firstOrFail();
    expect($rio->status)->toBe(ReturnInboundOrder::STATUS_PENDING_PUSH)
        ->and($rio->inbound_no)->toStartWith('RI')
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_APPROVED);   // 退货退款不立即放款

    // 4) 推送菜鸟
    wmsUatGatewaySuccess('CN-UAT-RET-3');
    wmsUatRunPushReturn();
    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_PUSHED);

    // 5) 菜鸟收货回传：实收 1 件（少件）
    $stockBefore = (int) Inventory::where('sku_id', $item->sku_id)->value('stock');

    Queue::fake();
    wmsPostCallback(wmsUatReturnConfirmPayload($rio, 'UAT-SKU-A', 1, 'ZP'), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();

    // 6) 库存只回加实收 1 件；退款按原额完成；订单 refunded
    $stockAfter = (int) Inventory::where('sku_id', $item->sku_id)->value('stock');
    expect($stockAfter - $stockBefore)->toBe(1)
        ->and($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED)
        ->and($refund->refresh()->return_exception_reason)->toContain('实收 1 件 ≠ 应退 2 件')
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($order->refresh()->status)->toBe(Order::STATUS_REFUNDED);
});

// ==================== 痕迹：request_id + 耗时（联调报告 F1 的取数口径） ====================

test('TC-UAT-04 全链路留痕：每次调用都有 request_id 与耗时，且 request_id 不重复', function () {
    wmsUatConfig();
    ['auth' => $auth] = wmsUatBuyer();

    $order = wmsUatPlaceAndPay($auth, [[$this->skuNormal, 1]], 'south');
    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();

    wmsUatGatewaySuccess('CN-UAT-4');
    wmsUatRunPushOutbound();

    Queue::fake();
    wmsPostCallback(wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF777', 'weight' => '1.000',
        'items' => ['item' => [['itemCode' => 'UAT-SKU-A', 'quantity' => 1]]],
    ]]), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();

    $logs = WmsApiLog::query()->where('biz_no', $fo->outbound_no)->get();

    // 出站推送 1 条 + 入站回调接收 1 条（异步处理不重复留痕）
    expect($logs->count())->toBeGreaterThanOrEqual(2);

    foreach ($logs as $log) {
        expect($log->request_id)->not->toBeEmpty();
        expect($log->duration_ms)->not->toBeNull()
            ->and((int) $log->duration_ms)->toBeGreaterThanOrEqual(0);
    }

    // request_id 全局唯一（重试沿用同一幂等键，重复投递才允许相同）
    expect($logs->pluck('request_id')->unique()->count())->toBe($logs->count());
});
