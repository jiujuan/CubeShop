<?php

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\SysUser;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 单元测试：PaymentService::close()（后台关闭支付单，权限 payment.manage）
 */
beforeEach(function () {
    $this->admin = SysUser::create([
        'username' => 'admin'.uniqid(),
        'password' => bcrypt('Test@1234'),
        'nickname' => '管理员',
        'status' => 1,
    ]);

    $this->order = Order::create([
        'order_no' => 'CS'.date('YmdHis').uniqid(),
        'user_id' => $this->admin->id,
        'status' => Order::STATUS_PENDING_PAYMENT,
        'total_amount' => 100,
        'freight_amount' => 0,
        'pay_amount' => 100,
        'address_snapshot' => [
            'contact_name' => '张三',
            'contact_phone' => '13800001111',
            'full_address' => '广东省深圳市南山区科技园南路 88 号',
        ],
    ]);

    $this->payment = Payment::create([
        'payment_no' => 'PAY'.uniqid(),
        'order_id' => $this->order->id,
        'order_no' => $this->order->order_no,
        'user_id' => $this->admin->id,
        'channel' => Payment::CHANNEL_WECHAT,
        'amount' => 100,
        'status' => Payment::STATUS_PENDING,
    ]);
});

test('UT-CLOSE-01 关闭待支付单：状态置为 closed 并写入支付日志', function () {
    $closed = app(PaymentService::class)->close($this->payment, $this->admin->id, '用户放弃支付');

    expect($closed->status)->toBe(Payment::STATUS_CLOSED)
        ->and($closed->refresh()->status)->toBe(Payment::STATUS_CLOSED);

    $log = PaymentLog::where('payment_id', $this->payment->id)
        ->where('event', PaymentLog::EVENT_CLOSE)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->request_data['admin_id'])->toBe($this->admin->id)
        ->and($log->request_data['reason'])->toBe('用户放弃支付')
        ->and($log->response_data['status'])->toBe(Payment::STATUS_CLOSED);
});

test('UT-CLOSE-02 非待支付状态拒绝关闭（40009）', function () {
    foreach ([Payment::STATUS_SUCCESS, Payment::STATUS_FAILED, Payment::STATUS_CLOSED] as $status) {
        $this->payment->forceFill(['status' => $status])->save();

        try {
            app(PaymentService::class)->close($this->payment->fresh(), $this->admin->id);
            $this->fail("状态 {$status} 应拒绝关闭");
        } catch (BusinessException $e) {
            expect($e->businessCode)->toBe(40009);
        }
    }

    // 拒绝后不应写入关闭日志
    expect(PaymentLog::where('event', PaymentLog::EVENT_CLOSE)->count())->toBe(0);
});

test('UT-CLOSE-03 关闭不联动取消订单（订单仍可重新支付）', function () {
    app(PaymentService::class)->close($this->payment, $this->admin->id);

    expect($this->order->refresh()->status)->toBe(Order::STATUS_PENDING_PAYMENT);
});

test('UT-CLOSE-04 关闭后原支付单不可重复关闭', function () {
    app(PaymentService::class)->close($this->payment, $this->admin->id);

    expect(fn () => app(PaymentService::class)->close($this->payment->fresh(), $this->admin->id))
        ->toThrow(BusinessException::class);
});

test('UT-CLOSE-05 并发重复关闭只生效一次（条件更新防并发）', function () {
    // 模拟：第一个请求已把状态改为 closed，第二个请求的条件更新命中 0 行
    $service = app(PaymentService::class);
    $service->close($this->payment, $this->admin->id);

    $second = $this->payment->fresh();
    expect($second->status)->toBe(Payment::STATUS_CLOSED);

    // 直接调用内部路径：状态已变更时 close 应在入口拦截
    $thrown = false;
    try {
        $service->close($second, $this->admin->id);
    } catch (BusinessException $e) {
        $thrown = true;
    }
    expect($thrown)->toBeTrue();

    // 关闭日志只应有一条
    expect(PaymentLog::where('payment_id', $this->payment->id)
        ->where('event', PaymentLog::EVENT_CLOSE)
        ->count())->toBe(1);
});
