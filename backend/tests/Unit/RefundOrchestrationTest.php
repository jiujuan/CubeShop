<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\UserBalanceLog;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\PaymentChannelService;
use App\Services\Common\NoGeneratorService;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
afterEach(fn () => Mockery::close());

/**
 * 构造一个 RefundService 实例（可注入假工厂），其余依赖走真实容器实现。
 */
function makeRefundService(?PaymentGatewayFactory $factory = null): RefundService
{
    return new RefundService(
        orders: app(OrderService::class),
        noGenerator: app(NoGeneratorService::class),
        operationLog: app(OperationLogService::class),
        inventory: app(InventoryService::class),
        factory: $factory ?? app(PaymentGatewayFactory::class),
        channels: app(PaymentChannelService::class),
    );
}

// O-01 余额支付仅退款：审核通过 → 真实退回用户余额 + 订单已退款 + refund_logs(success)
test('余额支付仅退款审核通过真实回退余额且订单已退款', function () {
    [$user, $sku, $order] = createPaidOrder(); // helper 默认生成 balance 成功支付单

    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, '不想要了', null);
    $admin = createTestUser('o1-admin');
    $refund = $service->process($refund, $admin->id, 'approve');

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->refunded_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and(UserBalanceLog::where('user_id', $user->id)->where('type', UserBalanceLog::TYPE_REFUND)->exists())
            ->toBeTrue()
        ->and($refund->refundLogs()->where('type', 'success')->exists())->toBeTrue()
        ->and($refund->refundLogs()->where('type', 'channel_request')->exists())->toBeTrue();
});

// O-02 微信异步退款：进入 processing 且订单保持退款中（不靠审核即 success）
test('微信异步退款进入 processing 且订单保持退款中', function () {
    [$user, $sku, $order] = createPaidOrder();
    // 把支付单渠道改为微信（真实场景），网关用假实现返回 PROCESSING
    Payment::where('order_id', $order->id)->update(['channel' => Payment::CHANNEL_WECHAT]);

    $gw = Mockery::mock(PaymentGateway::class);
    $gw->shouldReceive('channel')->andReturn(Payment::CHANNEL_WECHAT);
    $gw->shouldReceive('refund')->andReturn(RefundResult::success('WXREF1', ['status' => 'PROCESSING'], 'PROCESSING'));
    $factory = Mockery::mock(PaymentGatewayFactory::class);
    $factory->shouldReceive('make')->andReturn($gw);

    $service = makeRefundService($factory);
    $refund = $service->apply($order, $user->id, 'x', null);
    $admin = createTestUser('o2-admin');
    $refund = $service->process($refund, $admin->id, 'approve');

    expect($refund->status)->toBe(Refund::STATUS_PROCESSING)
        ->and($refund->refund_status)->toBe('PROCESSING')
        ->and($refund->channel_refund_no)->toBe('WXREF1')
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDING)
        ->and($refund->refundLogs()->where('type', 'channel_response')->exists())->toBeTrue();
});

// O-03 网关失败 → failed + failed_reason；重试复用 out_refund_no → 终态成功
test('网关失败进入 failed 且重试复用幂等单号终态成功', function () {
    [$user, $sku, $order] = createPaidOrder();

    $failGw = Mockery::mock(PaymentGateway::class);
    $failGw->shouldReceive('channel')->andReturn(Payment::CHANNEL_BALANCE);
    $failGw->shouldReceive('refund')->andReturn(RefundResult::fail('渠道余额不足'));
    $failFactory = Mockery::mock(PaymentGatewayFactory::class);
    $failFactory->shouldReceive('make')->andReturn($failGw);
    $svcFail = makeRefundService($failFactory);

    $refund = $svcFail->apply($order, $user->id, 'x', null);
    $admin = createTestUser('o3-admin');
    $refund = $svcFail->process($refund, $admin->id, 'approve');

    expect($refund->status)->toBe(Refund::STATUS_FAILED)
        ->and($refund->failed_reason)->toBe('渠道余额不足')
        ->and($refund->out_refund_no)->not->toBeNull();
    $no = $refund->out_refund_no;

    // 换成功网关重试
    $okGw = Mockery::mock(PaymentGateway::class);
    $okGw->shouldReceive('channel')->andReturn(Payment::CHANNEL_BALANCE);
    $okGw->shouldReceive('refund')->andReturn(RefundResult::success('OK1', ['ok' => 1], 'SUCCESS'));
    $okFactory = Mockery::mock(PaymentGatewayFactory::class);
    $okFactory->shouldReceive('make')->andReturn($okGw);
    $svcOk = makeRefundService($okFactory);

    $refund = $svcOk->retry($refund, $admin->id);

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->out_refund_no)->toBe($no)        // 幂等复用
        ->and($refund->retry_count)->toBe(1)
        ->and($refund->refundLogs()->where('type', 'retry')->exists())->toBeTrue();
});

// O-04 非 failed 态不可重试
test('非 failed 状态不可重试', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null);
    $admin = createTestUser('o4-admin');
    $refund = $service->process($refund, $admin->id, 'approve'); // 余额 → success

    expect(fn () => $service->retry($refund, $admin->id))
        ->toThrow(\App\Exceptions\BusinessException::class, '仅失败状态的退款可重试');
});

// O-05 达 MAX_RETRY 转人工且禁止继续重试
test('达最大重试次数转人工且禁止继续重试', function () {
    [$user, $sku, $order] = createPaidOrder();

    $failGw = Mockery::mock(PaymentGateway::class);
    $failGw->shouldReceive('channel')->andReturn(Payment::CHANNEL_BALANCE);
    $failGw->shouldReceive('refund')->andReturn(RefundResult::fail('一直失败'));
    $failFactory = Mockery::mock(PaymentGatewayFactory::class);
    $failFactory->shouldReceive('make')->andReturn($failGw);
    $svc = makeRefundService($failFactory);

    $refund = $svc->apply($order, $user->id, 'x', null);
    $admin = createTestUser('o5-admin');
    $refund = $svc->process($refund, $admin->id, 'approve'); // FAILED, retry_count=0

    $refund = $svc->retry($refund, $admin->id); // 1
    expect($refund->retry_count)->toBe(1)->and($refund->status)->toBe(Refund::STATUS_FAILED);
    $refund = $svc->retry($refund, $admin->id); // 2
    expect($refund->retry_count)->toBe(2);
    $refund = $svc->retry($refund, $admin->id); // 3 → 达上限，failed_reason 转人工
    expect($refund->retry_count)->toBe(3)
        ->and($refund->failed_reason)->toBe('已达最大重试次数，转人工');

    expect(fn () => $svc->retry($refund, $admin->id))
        ->toThrow(\App\Exceptions\BusinessException::class, '已达最大重试次数');
});

// O-06 退货退款收货后同样走 executeChannelRefund（余额同步成功）
test('退货退款确认收货经网关退款成功且订单已退款', function () {
    [$user, $sku, $order] = createPaidOrder();
    $service = app(RefundService::class);
    $refund = $service->apply($order, $user->id, 'x', null, [
        'type' => Refund::TYPE_RETURN_REFUND,
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ]);
    $admin = createTestUser('o6-admin');
    $service->process($refund, $admin->id, 'approve');
    $refund = $service->receiveReturn($refund, $admin->id, [['sku_id' => $sku->id, 'quantity' => 1, 'condition' => 'good']]);

    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->return_status)->toBe(Refund::RETURN_STATUS_RECEIVED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and($refund->refundLogs()->where('type', 'success')->exists())->toBeTrue();
});
