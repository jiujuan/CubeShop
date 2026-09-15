<?php

use App\Models\Order;
use App\Models\OrderLog;
use App\Services\Order\OrderLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-001：订单状态流水服务的单元测试。
 */
function makeOrderForLog(): Order
{
    $user = createTestUser('loguser');

    return Order::create([
        'order_no' => 'CS'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING_PAYMENT,
        'total_amount' => '100.00',
        'freight_amount' => '0.00',
        'pay_amount' => '100.00',
        'address_snapshot' => ['contact_name' => '张三'],
    ]);
}

test('record 写入流水并保留操作人信息', function () {
    $order = makeOrderForLog();
    $service = app(OrderLogService::class);

    $log = $service->record($order, Order::STATUS_PAID, OrderLog::OPERATOR_USER, 7, '支付成功', Order::STATUS_PENDING_PAYMENT);

    expect($log)->not->toBeNull()
        ->and($log->order_id)->toBe($order->id)
        ->and($log->from_status)->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($log->to_status)->toBe(Order::STATUS_PAID)
        ->and($log->operator_type)->toBe(OrderLog::OPERATOR_USER)
        ->and($log->operator_id)->toBe(7)
        ->and($log->created_at)->not->toBeNull();
});

test('三种操作人类型均可写入', function () {
    $order = makeOrderForLog();
    $service = app(OrderLogService::class);

    foreach ([OrderLog::OPERATOR_USER, OrderLog::OPERATOR_ADMIN, OrderLog::OPERATOR_SYSTEM] as $type) {
        $log = $service->record($order, Order::STATUS_PAID, $type, null, '测试');
        expect($log->operator_type)->toBe($type);
    }

    expect(OrderLog::where('order_id', $order->id)->count())->toBe(3);
});

test('同一订单多次流转按 id 有序', function () {
    $order = makeOrderForLog();
    $service = app(OrderLogService::class);

    $service->record($order, Order::STATUS_PAID, OrderLog::OPERATOR_SYSTEM, null, 'a');
    $service->record($order, Order::STATUS_SHIPPED, OrderLog::OPERATOR_ADMIN, 1, 'b');

    $timeline = $service->timeline($order);

    expect($timeline)->toHaveCount(2)
        ->and($timeline[0]['to_status'])->toBe(Order::STATUS_PAID)
        ->and($timeline[1]['to_status'])->toBe(Order::STATUS_SHIPPED)
        ->and($timeline[1]['to_status_label'])->toBe('已发货');
});

test('超长备注被截断为 255 字', function () {
    $order = makeOrderForLog();
    $log = app(OrderLogService::class)->record($order, Order::STATUS_PAID, 'system', null, str_repeat('长', 400));

    expect(mb_strlen($log->remark))->toBe(255);
});

test('recordCreated 生成 from_status 为空的初始流水', function () {
    $order = makeOrderForLog();
    $log = app(OrderLogService::class)->recordCreated($order, OrderLog::OPERATOR_USER, 3);

    expect($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($log->remark)->toBe('创建订单');
});
