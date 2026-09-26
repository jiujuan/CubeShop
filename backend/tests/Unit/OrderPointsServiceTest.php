<?php

use App\Models\Order;
use App\Models\UserPointLog;
use App\Services\Common\ConfigService;
use App\Services\Member\OrderPointsService;
use App\Services\Member\PointsService;
use App\Services\Member\PointsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 消费返积分纯逻辑（会员成长计划 S3）
 *
 * 只测 OrderPointsService::award 的计算与幂等，不经由 transitionTo：
 * - 实付 × earn_rate 发分，运费不计（默认）
 * - earn_on_freight=1 时基数含运费
 * - 0 元单 / earn_rate=0 / 功能关闭 → 不返
 * - 同一订单重复 award 只产生一条流水（biz_key 幂等）
 */
beforeEach(function () {
    $this->user = createTestUser('earnuser');
    $this->service = new OrderPointsService(app(PointsService::class), app(PointsSettings::class));
});

if (! function_exists('s3EarnOrder')) {
    function s3EarnOrder(int $userId, string $pay, string $freight, string $no): Order
    {
        return Order::create([
            'order_no' => $no.uniqid(),
            'user_id' => $userId,
            'status' => Order::STATUS_PAID,
            'total_amount' => bcadd($pay, $freight, 2),
            'pay_amount' => $pay,
            'freight_amount' => $freight,
            'address_snapshot' => ['name' => '测试', 'phone' => '13800000000', 'address' => '测试地址'],
        ]);
    }
}

it('按实付与 earn_rate 发分，运费不计', function () {
    app(ConfigService::class)->set('points.earn_rate', '100');
    app(ConfigService::class)->set('points.earn_on_freight', '0');

    $order = s3EarnOrder($this->user->id, '500.00', '100.00', 'CS-EARN-');
    $log = $this->service->award($order);

    expect($log)->not->toBeNull();
    expect($log->points)->toBe(4); // (500 - 100) / 100 = 4
    expect($log->type)->toBe('earn');
    expect(app(PointsService::class)->balance($this->user->id))->toBe(4);
});

it('运费计入时基数含运费', function () {
    app(ConfigService::class)->set('points.earn_rate', '100');
    app(ConfigService::class)->set('points.earn_on_freight', '1');

    $order = s3EarnOrder($this->user->id, '500.00', '100.00', 'CS-EARN-');
    $log = $this->service->award($order);

    // pay_amount 已含运费：开关打开时基数 = 实付 500（不再额外加回）
    expect($log->points)->toBe(5);
});

it('0 元单不返积分', function () {
    app(ConfigService::class)->set('points.earn_rate', '100');
    $order = s3EarnOrder($this->user->id, '0.00', '0.00', 'CS-EARN-');

    expect($this->service->award($order))->toBeNull();
});

it('earn_rate=0 不返积分', function () {
    app(ConfigService::class)->set('points.earn_rate', '0');
    $order = s3EarnOrder($this->user->id, '500.00', '0.00', 'CS-EARN-');

    expect($this->service->award($order))->toBeNull();
});

it('功能关闭不返积分', function () {
    app(ConfigService::class)->set('points.enabled', '0');
    app(ConfigService::class)->set('points.earn_rate', '100');
    $order = s3EarnOrder($this->user->id, '500.00', '0.00', 'CS-EARN-');

    expect($this->service->award($order))->toBeNull();
});

it('幂等：重复 award 只产生一条流水', function () {
    app(ConfigService::class)->set('points.earn_rate', '100');
    $order = s3EarnOrder($this->user->id, '500.00', '0.00', 'CS-EARN-');

    $this->service->award($order);
    $this->service->award($order);

    expect(UserPointLog::where('biz_key', 'order:'.$order->id.':earn')->count())->toBe(1);
    expect(app(PointsService::class)->balance($this->user->id))->toBe(5); // 500 / 100
});
