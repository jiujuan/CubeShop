<?php

use App\Events\RefundResult;
use App\Jobs\Wms\CancelReturnInboundJob;
use App\Jobs\Wms\PushReturnInboundJob;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnInboundOrder;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Services\Common\NoGeneratorService;
use App\Services\Wms\ReturnInboundOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * 退货入库单服务（WMS 计划 P4 / §4.1 单元测试）
 *
 * 覆盖：建单幂等/选仓/缺映射异常、状态机非法流转、收货回传四象限
 * （正品/残次/少件/实收0/超收）、complete 幂等（不二次加库存/放款）、
 * 退款完成 → 订单 refunded + RefundResult 事件、取消推送撤单。
 */

/** 建仓 + WMS 配置，返回 config */
function p4uConfig(array $attrs = []): WmsConfig
{
    $warehouse = Warehouse::create(['code' => 'WH_P4U_'.uniqid(), 'name' => '退货测试仓', 'status' => 1]);

    $config = WmsConfig::create(array_merge([
        'warehouse_id' => $warehouse->id,
        'provider' => 'mock',
        'enabled' => true,
        'auto_push' => false,
        'auto_push_return' => false,
        'push_retry_times' => 3,
        'sku_mapping_mode' => 'same',
        'api_env' => 'sandbox',
        'remark' => '',
        'callback_token' => 'ptoken'.bin2hex(random_bytes(12)),
    ], $attrs));

    $config->app_secret = 'P4U_SECRET';
    $config->save();

    return $config->refresh();
}

/** 建一笔已审核（approved + waiting_return）的退货退款单，返回 [refund, sku, order] */
function p4uRefund(?WmsConfig $config = null, int $qty = 2): array
{
    $sku = createTestSku(stock: 20, price: '50.00');
    $order = Order::create([
        'order_no' => 'CS'.now()->format('Ymd').random_int(1000000000, 9999999999),
        'user_id' => createTestUser('p4u')->id,
        'status' => Order::STATUS_REFUNDING,
        'total_amount' => 100, 'pay_amount' => 100, 'discount_amount' => 0,
        'promotion_discount' => 0, 'freight_amount' => 0,
        'address_snapshot' => [],
        'warehouse_id' => $config?->warehouse_id,
    ]);

    $refund = Refund::create([
        'refund_no' => app(NoGeneratorService::class)->generateRefundNo(),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $order->user_id,
        'type' => Refund::TYPE_RETURN_REFUND,
        'warehouse_id' => $config?->warehouse_id,
        'amount' => '100.00',
        'reason' => '七天无理由',
        'status' => Refund::STATUS_APPROVED,
        'return_status' => Refund::RETURN_STATUS_WAITING_RETURN,
        'return_details' => [
            ['sku_id' => $sku->id, 'product_title' => '测试商品', 'sku_specs' => [], 'quantity' => $qty],
        ],
    ]);

    return [$refund, $sku, $order];
}

/** 把入库单推进到 pushed（收货回传的前提状态） */
function p4uToPushed(ReturnInboundOrderService $svc, ReturnInboundOrder $rio): ReturnInboundOrder
{
    return $svc->markPushed(
        $svc->markPushing($svc->markPendingPush($rio), 'req-'.uniqid()),
        'CN-RET-'.uniqid(),
    );
}

test('P4U-01 createForRefund 幂等：同一退款单重复调用只建一张入库单', function () {
    Bus::fake();
    $config = p4uConfig();
    [$refund] = p4uRefund($config);

    $svc = app(ReturnInboundOrderService::class);
    $first = $svc->createForRefund($refund);
    $second = $svc->createForRefund($refund);

    expect($second->id)->toBe($first->id)
        ->and(ReturnInboundOrder::count())->toBe(1)
        ->and($first->status)->toBe(ReturnInboundOrder::STATUS_CREATED)   // auto_push_return=false
        ->and($first->inbound_no)->toStartWith('RI')
        ->and($first->refund_no)->toBe($refund->refund_no)
        ->and($first->items()->count())->toBe(1)
        ->and((int) $first->items()->first()->qty)->toBe(2);
});

test('P4U-02 auto_push_return=true → pending_push 并派发推送作业', function () {
    Bus::fake();
    $config = p4uConfig(['auto_push_return' => true]);
    [$refund] = p4uRefund($config);

    $rio = app(ReturnInboundOrderService::class)->createForRefund($refund);

    expect($rio->status)->toBe(ReturnInboundOrder::STATUS_PENDING_PUSH);
    Bus::assertDispatched(PushReturnInboundJob::class, 1);
});

test('P4U-03 createForRefund 缺仓（无配置、订单无仓、无任何仓）→ 400', function () {
    [$refund] = p4uRefund(null);
    $refund->forceFill(['warehouse_id' => null])->save();
    $refund->order()->first()->forceFill(['warehouse_id' => null])->save();

    expect(fn () => app(ReturnInboundOrderService::class)->createForRefund($refund))
        ->toThrow(\App\Exceptions\BusinessException::class);
});

test('P4U-04 createForRefund 仓库未配置 WMS → 400', function () {
    Bus::fake();
    $warehouse = Warehouse::create(['code' => 'WH_NOCONF'.uniqid(), 'name' => '无配置仓', 'status' => 1]);
    [$refund] = p4uRefund(null);
    $refund->forceFill(['warehouse_id' => $warehouse->id])->save();

    expect(fn () => app(ReturnInboundOrderService::class)->createForRefund($refund))
        ->toThrow(\App\Exceptions\BusinessException::class, '尚未配置 WMS');
});

test('P4U-05 manual 映射缺编码 → 入库单落 exception 并写明原因（不中断建单）', function () {
    Bus::fake();
    $config = p4uConfig(['sku_mapping_mode' => 'manual']);
    [$refund] = p4uRefund($config);

    $rio = app(ReturnInboundOrderService::class)->createForRefund($refund);

    expect($rio->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($rio->exception_reason)->toContain('WMS 货品编码');
});

test('P4U-06 状态机非法流转 → 409（pending_push 直达 received）', function () {
    Bus::fake();
    $config = p4uConfig();
    [$refund] = p4uRefund($config);
    $svc = app(ReturnInboundOrderService::class);

    $pending = $svc->markPendingPush($svc->createForRefund($refund));

    expect(fn () => $svc->markReceived($pending, [['sku_id' => 1, 'quantity' => 1]]))
        ->toThrow(\App\Exceptions\BusinessException::class);
});

test('P4U-07 正品足额收货：库存按实收回加、退款 success、订单 refunded、RefundResult 一次', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku, $order] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $rio = $svc->markReceived($rio, [
        ['platform_sku_code' => $rio->items()->first()->platform_sku_code, 'quantity' => 2, 'inventory_type' => 'ZP'],
    ]);

    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_RECEIVED)
        ->and($order->refresh()->status)->toBe(Order::STATUS_REFUNDED);

    Event::assertDispatched(RefundResult::class, 1);
});

test('P4U-08 残次（CC）收货：库存不回加、退款仍完成、明细标 defective', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $svc->markReceived($rio, [
        ['platform_sku_code' => $rio->items()->first()->platform_sku_code, 'quantity' => 2, 'inventory_type' => 'CC'],
    ]);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_received_details[0]['condition'])->toBe(Refund::RETURN_CONDITION_DEFECTIVE)
        ->and($rio->refresh()->items()->first()->inventory_type)->toBe('CC');
});

test('P4U-09 少件收货（1/2）：库存 +1、差额记入 return_exception_reason、退款仍完成', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $svc->markReceived($rio, [
        ['platform_sku_code' => $rio->items()->first()->platform_sku_code, 'quantity' => 1, 'inventory_type' => 'ZP'],
    ]);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 1)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_exception_reason)->toContain('实收 1 件 ≠ 应退 2 件');
});

test('P4U-10 实收 0 → 入库单 exception，退款保持 approved 等人工（不放款）', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $svc->markReceived($rio, [
        ['platform_sku_code' => $rio->items()->first()->platform_sku_code, 'quantity' => 0, 'inventory_type' => 'ZP'],
    ]);

    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($rio->exception_reason)->toContain('实收数量为 0')
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_APPROVED)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);

    Event::assertNotDispatched(RefundResult::class);
});

test('P4U-11 超收（3/2）→ exception 不做破坏性操作：库存不回加、退款不放款', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $svc->markReceived($rio, [
        ['platform_sku_code' => $rio->items()->first()->platform_sku_code, 'quantity' => 3, 'inventory_type' => 'ZP'],
    ]);

    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($rio->exception_reason)->toContain('超过应退 2 件')
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_APPROVED)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore);
});

test('P4U-12 回传未知货品编码 → exception 转人工', function () {
    Bus::fake();
    $config = p4uConfig();
    [$refund] = p4uRefund($config);

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $svc->markReceived($rio, [['platform_sku_code' => 'SKU-UNKNOWN', 'quantity' => 1]]);

    expect($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_EXCEPTION)
        ->and($rio->exception_reason)->toContain('未知货品');
});

test('P4U-13 complete 幂等：重复调用库存不二次增加、RefundResult 不重复', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));
    $svc->markReceived($rio, [
        ['platform_sku_code' => $rio->items()->first()->platform_sku_code, 'quantity' => 2, 'inventory_type' => 'ZP'],
    ]);

    // 模拟重复触发的 complete：终态短路，库存不二次增加
    $svc->complete($rio);
    $svc->complete($rio);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2)
        ->and($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED);

    Event::assertDispatched(RefundResult::class, 1);
});

test('P4U-14 已推送的入库单取消 → 派发 WMS 撤单作业；重复取消 409', function () {
    Bus::fake();
    $config = p4uConfig();
    [$refund] = p4uRefund($config);

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));

    $svc->cancel($rio, '买家撤销退货', 1);

    Bus::assertDispatched(CancelReturnInboundJob::class, 1);

    expect(fn () => $svc->cancel($rio->refresh(), '再来一次', 1))
        ->toThrow(\App\Exceptions\BusinessException::class);
});

test('P4U-15 manualReceived 缺省按全量正品收货（回传丢失兜底）', function () {
    Event::fake([RefundResult::class]);
    Bus::fake();
    $config = p4uConfig();
    [$refund, $sku] = p4uRefund($config);
    $stockBefore = (int) Inventory::where('sku_id', $sku->id)->value('stock');

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));

    $svc->manualReceived($rio, null, 1);

    expect((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe($stockBefore + 2)
        ->and($refund->refresh()->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($rio->refresh()->status)->toBe(ReturnInboundOrder::STATUS_COMPLETED);
});

test('P4U-16 retryPush：已推送状态不允许重推 → 409；异常修复后可回 pending_push', function () {
    Bus::fake();
    $config = p4uConfig();
    [$refund] = p4uRefund($config);

    $svc = app(ReturnInboundOrderService::class);
    $rio = p4uToPushed($svc, $svc->createForRefund($refund));

    expect(fn () => $svc->retryPush($rio, 1))
        ->toThrow(\App\Exceptions\BusinessException::class);

    $svc->markException($rio->refresh(), '回传异常');
    $fixed = $svc->retryPush($rio->refresh(), 1);

    expect($fixed->status)->toBe(ReturnInboundOrder::STATUS_PENDING_PUSH)
        ->and($fixed->push_request_id)->toBeNull();
});
