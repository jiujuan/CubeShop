<?php

use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * 出库单推送作业 · 真实菜鸟适配器链路（WMS 计划 P2 / F5、Step 6）
 *
 * 与 `tests/Unit/PushOutboundJobTest.php`（用 Mock/fail-closed 触发失败）互补：
 * 这里把 `wms_configs` 配成**凭证齐备**，让工厂解析出 `CainiaoAdapter`，
 * 再用 `Http::fake()` 扮演菜鸟网关，验证「作业 → 真实 Adapter → 回执处置」整条链路。
 */

beforeEach(function () {
    config(['wms.providers.cainiao.gateway.sandbox' => 'https://qimen.sandbox.test/gw']);
});

/** 建仓 + 凭证齐备的菜鸟配置 + 一张 pending_push 发货单（单行 2 件） */
function cnJobFulfillment(string $status = FulfillmentOrder::STATUS_PENDING_PUSH): FulfillmentOrder
{
    $warehouse = Warehouse::create(['code' => 'WH_CNJOB_'.uniqid(), 'name' => '菜鸟作业仓', 'status' => 1]);

    $config = WmsConfig::create([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'app_key' => 'cn-job-key',
        'customer_id' => 'CUBE_OWNER',
        'warehouse_code' => 'CN-WH-1',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('j', 24).uniqid(),
    ]);
    $config->app_secret = 'cn-job-secret';
    $config->save();

    $user = createTestUser('cnjobusr');

    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [
            'contact_name' => '收件人', 'contact_phone' => '13800008000',
            'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
            'detail_address' => '科技路 1 号', 'full_address' => '广东省深圳市南山区科技路 1 号',
        ],
        'warehouse_id' => $warehouse->id,
    ]);

    $fo = FulfillmentOrder::create([
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'outbound_no' => 'FO'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'status' => $status,
        'buyer_info' => $order->address_snapshot,
        'extend' => [],
    ]);

    $fo->items()->create([
        'sku_id' => null,
        'platform_sku_code' => 'SKU-CN',
        'wms_sku_code' => 'W-CN',
        'product_name' => '菜鸟测试商品',
        'qty' => 2,
        'shipped_qty' => 0,
    ]);

    return $fo->load('items');
}

/**
 * 构造一个假回执。
 *
 * ⚠️ 本项目 Laravel 版的 `Http::response()` 返回 `PromiseInterface`（不是 `Response`），
 * 而 `Http::fake()` 的 stub 恰好就吃这个 promise；因此**不能**给它声明 `Response` 返回类型。
 *
 * @return mixed
 */
function cnFakeResponse(string $json, int $status = 200)
{
    return Http::response($json, $status);
}

/** 菜鸟成功回执 */
function cnSuccessResponse(string $deliveryOrderId = 'CN-JOB-001')
{
    return cnFakeResponse(json_encode([
        'response' => ['flag' => 'success', 'code' => '0', 'deliveryOrderId' => $deliveryOrderId],
    ]));
}

test('TC-CNJOB-01 真实链路推送成功 → 已推送并记录菜鸟单号', function () {
    Http::fake(['*' => cnSuccessResponse('CN-JOB-001')]);

    $fo = cnJobFulfillment();
    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($fo->wms_outbound_no)->toBe('CN-JOB-001')
        ->and($fo->push_times)->toBe(1)
        ->and($fo->last_push_error)->toBeNull();

    // 请求确实带上了签名与业务报文
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'taobao.qimen.deliveryorder.create')
            && str_contains($request->url(), 'sign=')
            && str_contains($request->body(), 'W-CN');
    });

    $log = WmsApiLog::where('biz_no', $fo->outbound_no)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->api_name)->toBe('createOutbound')
        ->and((bool) $log->success)->toBeTrue();
});

test('TC-CNJOB-02 幂等回执（对方说单据已存在）→ 照常置已推送，不重复建单', function () {
    Http::fake(['*' => cnFakeResponse(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S07', 'message' => '单据已存在', 'deliveryOrderId' => 'CN-EXIST-5'],
    ]))]);

    $fo = cnJobFulfillment();
    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($fo->wms_outbound_no)->toBe('CN-EXIST-5');
});

test('TC-CNJOB-03 不可重试失败（S03 参数错）→ 立即转推送失败，不抛异常、不打扰队列', function () {
    Http::fake(['*' => cnFakeResponse(json_encode([
        'response' => ['flag' => 'failure', 'code' => 'S03', 'message' => 'itemCode 不存在'],
    ]))]);

    $fo = cnJobFulfillment();

    // 不抛异常（区分于可重试路径）
    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSH_FAILED)
        ->and($fo->push_times)->toBe(1)
        ->and($fo->last_push_error)->toContain('S03');

    $log = WmsApiLog::where('biz_no', $fo->outbound_no)->latest('id')->first();
    expect((bool) $log->success)->toBeFalse();
});

test('TC-CNJOB-04 可重试失败（5xx）→ 抛异常交队列，次数累加、状态停在推送中', function () {
    Http::fake(['*' => cnFakeResponse('upstream error', 503)]);

    $fo = cnJobFulfillment();

    expect(fn () => (new PushOutboundJob($fo->id, 3))->handle())
        ->toThrow(\RuntimeException::class);

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSHING)
        ->and($fo->push_times)->toBe(1)
        ->and($fo->last_push_error)->not->toBeNull();
});

test('TC-CNJOB-05 可重试失败累计到配置次数 → 转推送失败不再抛异常', function () {
    Http::fake(['*' => cnFakeResponse('', 500)]);

    $fo = cnJobFulfillment();

    foreach ([1, 2] as $attempt) {
        expect(fn () => (new PushOutboundJob($fo->id, 3))->handle())
            ->toThrow(\RuntimeException::class);
        expect($fo->fresh()->push_times)->toBe($attempt);
    }

    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSH_FAILED)
        ->and($fo->push_times)->toBe(3);
});

test('TC-CNJOB-06 报文留痕脱敏：入参手机号落库为掩码，AppSecret 不入库', function () {
    Http::fake(['*' => cnSuccessResponse()]);

    $fo = cnJobFulfillment();
    (new PushOutboundJob($fo->id, 3))->handle();

    $log = WmsApiLog::where('biz_no', $fo->outbound_no)->latest('id')->first();
    $payload = json_encode($log->request_body, JSON_UNESCAPED_UNICODE);

    // 手机号保留后 4 位
    expect($log->request_body['receiver_phone'])->toBe('*******8000')
        ->and($payload)->not->toContain('13800008000')
        // AppSecret 不在请求入参与日志中（即便它出现在 URL 里也不会进 body/日志）
        ->and($payload)->not->toContain('cn-job-secret');
});
