<?php

use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\ReturnInboundOrder;
use App\Models\Shipping;
use App\Models\WmsApiLog;
use App\Models\WmsCallbackDedup;
use App\Models\WmsSkuMapping;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 异常演练（WMS 计划 P7 / Step 3 · 9 项）
 *
 * 与 P1～P4 的「分层单测」不同，这里每一条都对应**联调手册里的一次故障注入**：
 * 注入方式 → 期望行为 → 实际结果，一次写成可重复执行的用例，
 * 沙箱联调时照着同一张表做一遍，结果填进 `docs/testing/wms/exception_drill.md`。
 *
 * 演练的意义不在于「再测一遍功能」，而在于**确认失败路径不会静默丢单**：
 * 推送超时有人管（push_failed 可见）、回调丢了能补（wms:query-outbound）、
 * 重复到达不会重复发货/重复加库存、异常单不阻塞其它单。
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

    $this->sku = createTestSku(stock: 50, price: '50.00');
    $this->sku->forceFill(['sku_code' => 'DRILL-SKU-A'])->save();
});

/** 演练基线：建仓配置（唯一启用）+ 下单支付 → 待推送发货单 */
function drillReady(array $configAttrs = [], int $qty = 2): array
{
    $config = wmsUatConfig($configAttrs);
    ['auth' => $auth] = wmsUatBuyer();
    $order = wmsUatPlaceAndPay($auth, [[test()->sku, $qty]]);
    $fo = FulfillmentOrder::where('order_id', $order->id)->firstOrFail();

    return ['config' => $config, 'auth' => $auth, 'order' => $order, 'fo' => $fo];
}

// ============ DR-01 推送超时 ============

test('DR-01 推送超时（网关连接超时）→ 作业连续重试，达到次数上限后转推送失败且后台可见原因', function () {
    Http::fake(['*' => function () {
        throw new ConnectionException('cURL error 28: Connection timed out after 1 ms');
    }]);

    ['fo' => $fo] = drillReady(['push_retry_times' => 3]);
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PENDING_PUSH);

    // 前 2 次：可重试失败 → 抛异常交队列退避，状态停在推送中
    foreach ([1, 2] as $attempt) {
        expect(fn () => (new PushOutboundJob($fo->id, 3))->handle())->toThrow(\RuntimeException::class);
        expect($fo->fresh()->status)->toBe(FulfillmentOrder::STATUS_PUSHING)
            ->and($fo->fresh()->push_times)->toBe($attempt);
    }

    // 第 3 次：达到上限 → 转推送失败，不再打扰队列
    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSH_FAILED)
        ->and($fo->push_times)->toBe(3)
        ->and($fo->last_push_error)->toContain('网络异常');

    // 后台可见：列表能筛到，详情带失败原因
    $detail = $this->getJson('/api/admin/wms/fulfillment-orders/'.$fo->id, $this->adminAuth)->json('data');
    expect($detail['status'])->toBe('push_failed')
        ->and($detail['last_push_error'])->toContain('网络异常');
});

// ============ DR-02 重复推送 ============

test('DR-02 重复推送（已推送单手工二次推送）→ 幂等短路，网关零额外调用', function () {
    wmsUatGatewaySuccess('DR-02');
    ['fo' => $fo] = drillReady();

    wmsUatRunPushOutbound();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PUSHED);
    Http::assertSentCount(1);

    // 二次推送：作业直接短路（不会在 WMS 侧建出第二张出库单）
    (new PushOutboundJob($fo->id, 3))->handle();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($fo->push_times)->toBe(1);
    Http::assertSentCount(1);

    // 后台重推接口对已推送单同样安全（409 由状态机给出，不产生外呼）
    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/push", [], $this->adminAuth);
    Http::assertSentCount(1);
});

// ============ DR-03 回调丢失 ============

test('DR-03 回调丢失（屏蔽回传）→ 单据停在已推送，wms:query-outbound 主动查询补齐发货', function () {
    // create 走简单成功（只要 flag=success 即可标记 pushed）；query 走 SHIPPED 回执
    // 用 URL 区分，避免 Http::fake 二次重置对 artisan 子调用不生效的问题。
    Http::fake([
        '*deliveryorder.create*' => Http::response(json_encode([
            'response' => ['flag' => 'success', 'code' => '0', 'deliveryOrderId' => 'DR-03', 'returnOrderId' => 'DR-03'],
        ]), 200),
        '*deliveryorder.query*' => Http::response(json_encode([
            'response' => [
                'flag' => 'success',
                'code' => '0',
                'deliveryOrder' => [
                    'deliveryOrderId' => 'DR-03',
                    'status' => 'SHIPPED',
                    'packages' => ['package' => [[
                        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SFQ-1', 'weight' => '1.000',
                        'items' => ['item' => [['itemCode' => 'DRILL-SKU-A', 'quantity' => 2]]],
                    ]]],
                ],
            ],
        ]), 200),
    ]);

    ['fo' => $fo, 'order' => $order] = drillReady();

    wmsUatRunPushOutbound();

    // 回传被屏蔽：单据停在 pushed，订单仍是待发货
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($order->refresh()->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and(Shipping::where('order_id', $order->id)->count())->toBe(0);

    // 主动查询补齐：仓方已 SHIPPED → 按 confirm 语义补录运单 + 订单发货
    $this->artisan('wms:query-outbound', ['outboundNo' => $fo->outbound_no])->assertSuccessful();

    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_SHIPPED)
        ->and($fo->tracking_no)->toBe('SFQ-1')
        ->and($order->refresh()->status)->toBe(Order::STATUS_SHIPPED);
});

// ============ DR-04 重复回调 ============

test('DR-04 重复回调（同一事件重发，换 timestamp 绕过防重放）→ 业务幂等吞掉，不重复发货', function () {
    wmsUatGatewaySuccess('DR-04');
    ['fo' => $fo, 'order' => $order] = drillReady();
    wmsUatRunPushOutbound();

    Queue::fake();
    wmsPostCallback(wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF-DUP', 'weight' => '1.000',
        'items' => ['item' => [['itemCode' => 'DRILL-SKU-A', 'quantity' => 2]]],
    ]], '2026-09-20 10:00:00'), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();
    expect($order->refresh()->status)->toBe(Order::STATUS_SHIPPED);

    // 重发同一事件（换 timestamp，绕过 raw 防重放）
    Queue::fake();
    wmsPostCallback(wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF-DUP', 'weight' => '1.000',
        'items' => ['item' => [['itemCode' => 'DRILL-SKU-A', 'quantity' => 2]]],
    ]], '2026-09-20 10:09:00'), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();

    expect(Shipping::where('order_id', $order->id)->count())->toBe(1)
        ->and(WmsCallbackDedup::count())->toBe(1)
        ->and($order->refresh()->status)->toBe(Order::STATUS_SHIPPED);
});

// ============ DR-05 验签失败 ============

test('DR-05 验签失败（篡改报文）→ 返回 failure + 留痕 SIGNATURE_INVALID，业务零变更', function () {
    wmsUatGatewaySuccess();
    ['fo' => $fo, 'order' => $order] = drillReady();
    wmsUatRunPushOutbound();

    Queue::fake();
    $payload = wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'expressCode' => 'SF-EVIL', 'items' => [],
    ]]);

    // 用**原文**算出签名 URL，再把 body 改掉发出去（模拟传输中被篡改 / 重放旧签名）
    $url = wmsSignedCallbackUrl($payload, WMS_UAT_APP_SECRET);
    $payload['deliveryOrder']['deliveryOrderId'] = 'TAMPERED';

    $res = test()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode($payload, JSON_UNESCAPED_UNICODE))->assertOk()->json();

    expect($res['flag'])->toBe('failure')
        ->and($res['code'])->toBe('SIGNATURE_INVALID');

    // 无业务变更：订单未发货、无包裹、回调作业未入队
    expect($order->refresh()->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and(Shipping::where('order_id', $order->id)->count())->toBe(0);
    Queue::assertNothingPushed();

    // 拒绝也要留痕（安全审计可检索）
    expect(WmsApiLog::where('direction', 'inbound')
        ->where('error_msg', 'like', '%SIGNATURE_INVALID%')->count())->toBe(1);
});

// ============ DR-06 缺 SKU 映射 ============

test('DR-06 缺 SKU 映射（manual 模式未配编码）→ 发货单转异常并给出原因；补齐后可重推且不阻塞其它单', function () {
    ['config' => $config, 'fo' => $fo] = drillReady(['sku_mapping_mode' => 'manual']);

    expect($fo->status)->toBe(FulfillmentOrder::STATUS_EXCEPTION)
        ->and($fo->exception_reason)->toContain('未配置 WMS 货品编码');

    // 不阻塞其它单：另一张有映射的单照常进入待推送（manual 模式补映射即通过）
    WmsSkuMapping::create([
        'warehouse_id' => $config->warehouse_id,
        'sku_id' => test()->sku->id,
        'platform_sku_code' => 'DRILL-SKU-A',
        'wms_sku_code' => 'WMS-DRILL-A',
        'status' => 1,
    ]);

    Queue::fake();
    $res = $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/push", [], $this->adminAuth);
    expect($res->json('code'))->toBe(0);

    // 重推走队列：驱动后转为已推送（异常单修复路径闭环）
    wmsUatGatewaySuccess('DR-06');
    wmsUatRunPushOutbound();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PUSHED);
});

// ============ DR-07 实收差异 ============

test('DR-07 实收差异（回传实收 < 应退）→ 库存按实收回加 + 差异记录 + 退款原额完成；重复回传不多加', function () {
    wmsUatGatewaySuccess('DR-07');
    ['auth' => $auth, 'order' => $order, 'fo' => $fo] = drillReady([], 2);
    wmsUatRunPushOutbound();

    Queue::fake();
    wmsPostCallback(wmsUatConfirmPayload($fo, [[
        'logisticsCode' => 'SF', 'logisticsName' => '顺丰速运', 'expressCode' => 'SF-DR7', 'weight' => '1.000',
        'items' => ['item' => [['itemCode' => 'DRILL-SKU-A', 'quantity' => 2]]],
    ]]), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();

    // 退货 2 件
    $item = OrderItem::where('order_id', $order->id)->first();
    $this->postJson("/api/orders/{$order->id}/refund", [
        'reason' => '少件演练',
        'type' => 'return_refund',
        'return_details' => [['sku_id' => $item->sku_id, 'quantity' => 2, 'product_title' => '演练商品', 'sku_specs' => []]],
    ], $auth)->assertOk();

    $refund = Refund::where('order_id', $order->id)->latest('id')->first();
    $amount = (string) $refund->amount;

    Queue::fake();
    $this->postJson('/api/admin/refunds/'.rfid($refund->id).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();
    $rio = ReturnInboundOrder::where('refund_id', $refund->id)->firstOrFail();

    wmsUatGatewaySuccess('DR-07-R');
    wmsUatRunPushReturn();

    // 仓方实收 1 件（少件）
    $stockBefore = (int) Inventory::where('sku_id', $item->sku_id)->value('stock');
    Queue::fake();
    wmsPostCallback(wmsUatReturnConfirmPayload($rio, 'DRILL-SKU-A', 1), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();

    expect((int) Inventory::where('sku_id', $item->sku_id)->value('stock'))->toBe($stockBefore + 1)
        ->and($refund->refresh()->return_exception_reason)->toContain('实收 1 件 ≠ 应退 2 件')
        // 退款金额以审核金额为准，不因实收差异自动改额
        ->and((string) $refund->refresh()->amount)->toBe($amount)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS);

    // 重复回传（换 timestamp）：不重复加库存
    Queue::fake();
    wmsPostCallback(wmsUatReturnConfirmPayload($rio, 'DRILL-SKU-A', 1, 'ZP', '2026-09-20 16:00:00'), WMS_UAT_APP_SECRET);
    wmsUatRunCallbacks();
    expect((int) Inventory::where('sku_id', $item->sku_id)->value('stock'))->toBe($stockBefore + 1);
});

// ============ DR-08 开关降级 ============

test('DR-08 开关降级（关闭 auto_push）→ 单据停在已创建不自动推送，后台人工可推', function () {
    ['fo' => $fo] = drillReady(['auto_push' => false]);

    expect($fo->status)->toBe(FulfillmentOrder::STATUS_CREATED)
        ->and($fo->push_times)->toBe(0);

    // 人工推送：入队即可（驱动后转已推送）
    Queue::fake();
    $this->postJson("/api/admin/wms/fulfillment-orders/{$fo->id}/push", [], $this->adminAuth)->assertOk();

    wmsUatGatewaySuccess('DR-08');
    wmsUatRunPushOutbound();
    expect($fo->refresh()->status)->toBe(FulfillmentOrder::STATUS_PUSHED);
});

// ============ DR-09 限流 ============

test('DR-09 限流（高频回调洪水）→ 超阈值返回 429，阈值内全部受理（不静默丢单）', function () {
    wmsUatConfig();

    $payload = ['method' => 'taobao.qimen.deliveryorder.confirm', 'app_key' => WMS_UAT_APP_KEY, 'v' => '2.0'];

    $accepted = 0;
    $limited = 0;

    // 阈值 120 次/分钟（AppServiceProvider 定义），多发 10 次观察越过阈值的表现
    for ($i = 0; $i < 130; $i++) {
        $res = wmsPostCallback($payload, WMS_UAT_APP_SECRET);
        if (($res['http_status'] ?? 200) === 429) {
            $limited++;
        } else {
            $accepted++;
        }
    }

    expect($accepted)->toBe(120)
        ->and($limited)->toBe(10);
});
