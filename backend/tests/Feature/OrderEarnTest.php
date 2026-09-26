<?php

use App\Models\Order;
use App\Models\UserPointLog;
use App\Services\Common\ConfigService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 消费返积分集成（会员成长计划 S3）
 *
 * 验收口径（设计文档 §16 S3）：
 * - 订单进入 paid（transitionTo 唯一执行点）后自动发分
 * - 退款驳回回退 paid 不重复发分（biz_key 幂等）
 * - 买家接口 GET /user/points、/user/points/logs 可见自己积分与流水
 * - 未登录 401
 */
beforeEach(function () {
    $this->user = createTestUser('earnbuyer');
    app(ConfigService::class)->set('points.earn_rate', '100');
    app(ConfigService::class)->set('points.earn_on_freight', '0');

    $this->order = Order::create([
        'order_no' => 'CS-FEAT-'.uniqid(),
        'user_id' => $this->user->id,
        'status' => Order::STATUS_PENDING_PAYMENT,
        'total_amount' => '320.00',
        'pay_amount' => '300.00',
        'freight_amount' => '20.00',
        'address_snapshot' => ['name' => '测试', 'phone' => '13800000000', 'address' => '测试地址'],
    ]);

    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

it('支付成功（进入 paid）自动发放积分（实付扣运费）', function () {
    expect(UserPointLog::count())->toBe(0);

    app(OrderService::class)->transitionTo($this->order, Order::STATUS_PAID);
    $this->order->refresh();

    expect($this->order->status)->toBe(Order::STATUS_PAID);
    // (300 - 20) / 100 = 2
    $log = UserPointLog::where('biz_key', 'order:'.$this->order->id.':earn')->first();
    expect($log)->not->toBeNull();
    expect($log->points)->toBe(2);
    expect($log->type)->toBe('earn');
    expect($log->related_id)->toBe($this->order->id);
});

it('退款驳回回退 paid 不重复发分（幂等）', function () {
    app(OrderService::class)->transitionTo($this->order, Order::STATUS_PAID);
    $this->order->refresh();
    app(OrderService::class)->transitionTo($this->order, Order::STATUS_REFUNDING);
    $this->order->refresh();
    // 退款被驳回：refunding → paid
    app(OrderService::class)->transitionTo($this->order, Order::STATUS_PAID);

    expect(UserPointLog::where('biz_key', 'order:'.$this->order->id.':earn')->count())->toBe(1);
    expect(UserPointLog::where('biz_key', 'order:'.$this->order->id.':earn')->first()->points)->toBe(2);
});

it('买家可查看我的积分概览与流水', function () {
    app(OrderService::class)->transitionTo($this->order, Order::STATUS_PAID);

    $this->withHeaders($this->auth)->getJson('/api/user/points')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.name', '积分')
        ->assertJsonPath('data.account.balance', 2)
        ->assertJsonPath('data.account.total_earn', 2)
        ->assertJsonPath('data.logs.0.type', 'earn');

    $this->withHeaders($this->auth)->getJson('/api/user/points/logs')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.list.0.points', 2);
});

it('未登录访问积分接口返回 401', function () {
    $this->getJson('/api/user/points')->assertUnauthorized();
    $this->getJson('/api/user/points/logs')->assertUnauthorized();
});

it('仅 paid 后发分：pending_payment 状态不触发', function () {
    // 订单仍为 pending_payment，未走 transitionTo，不应有流水
    expect(UserPointLog::count())->toBe(0);
});
