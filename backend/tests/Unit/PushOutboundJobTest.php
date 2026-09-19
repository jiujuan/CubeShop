<?php

use App\Jobs\Wms\PushOutboundJob;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\Warehouse;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 出库单推送作业（WMS 计划 P1 / F5、Step 6；P2 语义更新）
 *
 * 覆盖成功、幂等短路、终态短路、配置缺失、`tries` 上限兜底。
 *
 * ⚠️ P2 语义变化：失败不再「一律重试」。作业按 Adapter 给出的 `retryable` 分流——
 * 本文件用「生产缺凭证」触发 `BusinessException`（**不可重试**）路径；
 * **可重试**路径（网络/5xx，抛异常交队列退避）由 `tests/Feature/WmsPushOutboundTest.php`
 * 用真实 CainiaoAdapter + `Http::fake()` 覆盖。
 */

/** 建仓 + WMS 配置，返回发货单（默认 pending_push，单行 2 件） */
function jobFulfillment(string $status = FulfillmentOrder::STATUS_PENDING_PUSH, array $configAttrs = []): FulfillmentOrder
{
    $warehouse = Warehouse::create(['code' => 'WH_JOB_'.uniqid(), 'name' => '作业仓', 'status' => 1]);

    WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'cainiao',
        'enabled' => true,
        'auto_push' => true,
        'auto_push_return' => true,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'api_env' => 'sandbox',
        'callback_token' => str_repeat('j', 28).uniqid(),
    ], $configAttrs));

    $user = createTestUser('jobusr');

    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING_SHIP,
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [
            'contact_name' => '收件人', 'contact_phone' => '13800000000',
            'full_address' => '广东省深圳市南山区科技路 1 号',
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
        'platform_sku_code' => 'SKU-JOB',
        'wms_sku_code' => 'W-JOB',
        'product_name' => '作业测试商品',
        'qty' => 2,
        'shipped_qty' => 0,
    ]);

    return $fo->load('items');
}

test('推送成功：状态转已推送并记录 WMS 单号与一条成功报文日志', function () {
    $fo = jobFulfillment();

    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($fo->wms_outbound_no)->toStartWith('MOCK-OUT-')
        ->and($fo->push_times)->toBe(1)
        ->and($fo->last_push_at)->not->toBeNull()
        ->and($fo->last_push_error)->toBeNull();

    $log = WmsApiLog::where('biz_no', $fo->outbound_no)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->api_name)->toBe('createOutbound')
        ->and((bool) $log->success)->toBeTrue()
        ->and($log->direction)->toBe(WmsApiLog::DIRECTION_OUTBOUND);
});

test('作业幂等：已推送的单重复执行不会二次推送、也不新增报文日志', function () {
    $fo = jobFulfillment();

    (new PushOutboundJob($fo->id, 3))->handle();
    $firstNo = $fo->fresh()->wms_outbound_no;
    $logCount = WmsApiLog::where('biz_no', $fo->outbound_no)->count();

    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSHED)
        ->and($fo->wms_outbound_no)->toBe($firstNo)
        ->and($fo->push_times)->toBe(1)
        ->and(WmsApiLog::where('biz_no', $fo->outbound_no)->count())->toBe($logCount);
});

test('tries 来自配置传入，并兜底封顶 10 次', function () {
    expect((new PushOutboundJob(1, 5))->tries)->toBe(5)
        ->and((new PushOutboundJob(1, 1))->tries)->toBe(1)
        ->and((new PushOutboundJob(1, 99))->tries)->toBe(PushOutboundJob::MAX_TRIES)
        ->and((new PushOutboundJob(1, 0))->tries)->toBe(1);
});

test('我方问题（生产缺凭证）→ 不可重试：立即转推送失败、不抛异常、落失败日志', function () {
    // 生产环境缺凭证 → MockAdapter fail-closed 抛 BusinessException。
    // P2 起：这类「配置/数据问题」判为**不可重试**（重试一万次也一样），
    // 直接置 push_failed 转人工，不再抛异常去打扰队列。
    $fo = jobFulfillment(configAttrs: ['api_env' => 'prod']);

    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSH_FAILED)
        ->and($fo->push_times)->toBe(1)
        ->and($fo->last_push_error)->toContain('生产环境缺少 WMS 凭证');

    $log = WmsApiLog::where('biz_no', $fo->outbound_no)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and((bool) $log->success)->toBeFalse()
        ->and($log->error_msg)->toContain('生产环境缺少 WMS 凭证');
});

test('不可重试失败即便 tries 很大也不重试（不抛异常、只计一次、直接转人工）', function () {
    $fo = jobFulfillment(configAttrs: ['api_env' => 'prod']);

    (new PushOutboundJob($fo->id, 10))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSH_FAILED)
        ->and($fo->push_times)->toBe(1)
        ->and(WmsApiLog::where('biz_no', $fo->outbound_no)->count())->toBe(1);
});

test('终态发货单直接短路：已发货/已取消不再推送', function () {
    foreach ([FulfillmentOrder::STATUS_SHIPPED, FulfillmentOrder::STATUS_CANCELLED] as $status) {
        $fo = jobFulfillment($status);

        (new PushOutboundJob($fo->id, 3))->handle();

        $fo->refresh();
        expect($fo->status)->toBe($status)
            ->and($fo->push_times)->toBe(0)
            ->and(WmsApiLog::where('biz_no', $fo->outbound_no)->count())->toBe(0);
    }
});

test('仓库未配置 WMS 时直接转推送失败并写明原因', function () {
    $fo = jobFulfillment();

    // 抹掉配置，模拟「配置被删但发货单还在」
    WmsConfig::where('warehouse_id', $fo->warehouse_id)->delete();

    (new PushOutboundJob($fo->id, 3))->handle();

    $fo->refresh();
    expect($fo->status)->toBe(FulfillmentOrder::STATUS_PUSH_FAILED)
        ->and($fo->last_push_error)->toContain('未配置 WMS');
});
