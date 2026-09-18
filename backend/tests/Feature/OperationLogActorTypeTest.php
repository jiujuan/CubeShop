<?php

use App\Models\Order;
use App\Models\SysOperationLog;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 操作日志的操作人归属（跨模块回归）
 *
 * `sys_operation_log.user_id` 是**混合语义列**（管理员与买家共写），`actor_type` 决定
 * 操作人去 `sys_user` 还是 `users` 解析。凡买家侧写操作必须显式记 `customer`，
 * 否则后台「操作日志」/「退款处理记录」会把买家操作显示成管理员（退款 apply 曾踩坑）。
 *
 * 本用例锁定买家侧各入口的归属，防止后续新增买家操作时再漏传 actorType。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    // 管理员
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 买家
    $user = createTestUser('olog');
    $this->user = $user;
    $this->userAuth = ['Authorization' => 'Bearer '.$user->createToken('olog')->plainTextToken];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $this->userAuth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '50.00');
});

/** 取指定模块/动作的最新一条操作日志 */
function olog(string $module, string $action): ?SysOperationLog
{
    return SysOperationLog::where('module', $module)
        ->where('action', $action)
        ->orderByDesc('id')
        ->first();
}

/** 买家下单，返回订单 */
function placeOrder(): Order
{
    test()->postJson('/api/cart', ['sku_id' => test()->sku->id, 'quantity' => 1], test()->userAuth)->assertOk();
    $data = test()->postJson('/api/orders', ['address_id' => test()->addressId], test()->userAuth)->json('data');

    return Order::find(oid($data['order_id']));
}

/** 沙箱支付（订单进入已支付，可申请退款） */
function payOrder(Order $order): void
{
    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], test()->userAuth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], test()->userAuth)->assertOk();
}

/** 沙箱支付 + 管理员发货 */
function payAndShip(Order $order): void
{
    payOrder($order);

    test()->postJson('/api/admin/orders/'.$order->id.'/ship', [
        'express_company_code' => 'SF',
        'tracking_no' => 'SF'.strtoupper(substr(uniqid(), 0, 12)),
    ], test()->adminAuth)->assertOk();
}

test('TC-OPLOG-001 买家下单的操作日志归属买家', function () {
    placeOrder();

    $log = olog('order', 'create');
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(SysOperationLog::ACTOR_CUSTOMER)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->customer?->id)->toBe($this->user->id);
});

test('TC-OPLOG-002 买家取消订单的操作日志归属买家', function () {
    $order = placeOrder();

    $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => '不想要了'], $this->userAuth)->assertOk();

    $log = olog('order', 'status_'.Order::STATUS_CANCELLED);
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(SysOperationLog::ACTOR_CUSTOMER)
        ->and($log->customer?->id)->toBe($this->user->id);
});

test('TC-OPLOG-003 买家确认收货的操作日志归属买家', function () {
    $order = placeOrder();
    payAndShip($order);

    $this->postJson("/api/orders/{$order->id}/confirm", [], $this->userAuth)->assertOk();

    $log = olog('order', 'status_'.Order::STATUS_COMPLETED);
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(SysOperationLog::ACTOR_CUSTOMER)
        ->and($log->customer?->id)->toBe($this->user->id);
});

test('TC-OPLOG-004 管理员发货的操作日志归属管理员', function () {
    $order = placeOrder();
    payAndShip($order);

    $log = olog('order', 'status_'.Order::STATUS_SHIPPED);
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(SysOperationLog::ACTOR_ADMIN)
        ->and($log->admin)->not->toBeNull();
});

test('TC-OPLOG-005 买家申请退款：退款与订单状态流水均归属买家', function () {
    $order = placeOrder();
    payOrder($order);

    $this->postJson("/api/orders/{$order->id}/refund", ['reason' => '不想要了'], $this->userAuth)->assertOk();

    $apply = olog('refund', 'apply');
    $status = olog('order', 'status_'.Order::STATUS_REFUNDING);

    expect($apply)->not->toBeNull()
        ->and($apply->actor_type)->toBe(SysOperationLog::ACTOR_CUSTOMER)
        ->and($apply->customer?->id)->toBe($this->user->id)
        ->and($status)->not->toBeNull()
        ->and($status->actor_type)->toBe(SysOperationLog::ACTOR_CUSTOMER)
        ->and($status->customer?->id)->toBe($this->user->id);
});

test('TC-OPLOG-006 管理员同意退款：退款与订单状态流水均归属管理员', function () {
    $order = placeOrder();
    payOrder($order);
    $this->postJson("/api/orders/{$order->id}/refund", ['reason' => '不想要了'], $this->userAuth)->assertOk();

    $refundId = \App\Models\Refund::where('order_id', $order->id)->latest('id')->first()->id;
    $this->postJson('/api/admin/refunds/'.rfid($refundId).'/process', ['action' => 'approve'], $this->adminAuth)->assertOk();

    $process = olog('refund', 'process_approve');
    $status = olog('order', 'status_'.Order::STATUS_REFUNDED);

    expect($process)->not->toBeNull()
        ->and($process->actor_type)->toBe(SysOperationLog::ACTOR_ADMIN)
        ->and($process->admin)->not->toBeNull()
        ->and($status)->not->toBeNull()
        ->and($status->actor_type)->toBe(SysOperationLog::ACTOR_ADMIN);
});
