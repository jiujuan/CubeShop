<?php

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 集成测试：后台订单状态流水（order_logs，API 文档 8.13）
 * 只读接口，重点验证筛选能力与「下单 → 支付 → 发货」三表联动的完整性。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $login = function (string $username, string $password) {
        $cap = app(CaptchaService::class)->generate();

        return $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token');
    };

    $this->adminAuth = ['Authorization' => 'Bearer '.$login('admin', 'Admin@123')];
    $this->operatorAuth = ['Authorization' => 'Bearer '.$login('operator', 'Operator@123')];

    $cap = app(CaptchaService::class)->generate();
    $this->buyerUsername = 'buyer'.uniqid();
    $this->buyerToken = $this->postJson('/api/auth/register', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyerToken];
    $this->buyer = User::where('username', $this->buyerUsername)->first();

    $this->address = UserAddress::create([
        'user_id' => $this->buyer->id,
        'contact_name' => '张三',
        'contact_phone' => '13800001111',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
        'detail_address' => '科技园南路 88 号',
        'is_default' => true,
    ]);
});

/** 下单并（可选）完成支付，返回 [order, payment_no] */
function prepareOrder($test, bool $pay = true): array
{
    $sku = createTestSku(stock: 10, price: '60.00');
    $test->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $test->buyerAuth);
    $order = $test->postJson('/api/orders', ['address_id' => $test->address->id], $test->buyerAuth)->json('data');

    $paymentNo = null;
    if ($pay) {
        $paymentNo = $test->postJson('/api/payments', [
            'order_no' => $order['order_no'],
            'channel' => 'wechat',
        ], $test->buyerAuth)->json('data.payment_no');
        $test->postJson('/api/payments/sandbox/'.$paymentNo)->assertOk();
    }

    return [$order, $paymentNo];
}

// 权限：买家 403；运营可查看（order.log）
test('TC-OLOG-001 权限分层：买家 403、运营与超管可查看', function () {
    prepareOrder($this);

    $this->getJson('/api/admin/order-logs', $this->buyerAuth)->assertStatus(403);

    expect($this->getJson('/api/admin/order-logs', $this->operatorAuth)->json('code'))->toBe(0)
        ->and($this->getJson('/api/admin/order-logs', $this->adminAuth)->json('code'))->toBe(0);
});

// 全链路：下单 → 支付成功，order_logs 记录 pending_payment → paid（system 操作人）
test('TC-OLOG-002 下单支付成功写入状态流水', function () {
    [$order, $paymentNo] = prepareOrder($this);

    $list = $this->getJson('/api/admin/order-logs?order_no='.$order['order_no'], $this->adminAuth)->json('data.list');

    $flows = collect($list)->pluck('to_status')->all();
    expect($flows)->toContain(Order::STATUS_PENDING_PAYMENT)
        ->and($flows)->toContain(Order::STATUS_PAID);

    // 中文标签与操作人类型
    $paid = collect($list)->firstWhere('to_status', Order::STATUS_PAID);
    expect($paid['to_status_label'])->toBe('已支付')
        ->and($paid['order_no'])->toBe($order['order_no'])
        ->and($paid['operator_type'])->toBe(OrderLog::OPERATOR_SYSTEM);

    // 同时应有支付成功记录与回调日志
    expect(Payment::where('payment_no', $paymentNo)->value('status'))->toBe(Payment::STATUS_SUCCESS)
        ->and(PaymentLog::where('payment_no', $paymentNo)->where('event', PaymentLog::EVENT_CALLBACK)->count())->toBeGreaterThan(0);
});

// 后台发货：产生 admin 操作人的流水，并可按操作人类型筛选
test('TC-OLOG-003 发货产生管理员流水且可按操作人类型筛选', function () {
    [$order] = prepareOrder($this);

    $this->postJson('/api/admin/orders/'.$order['order_id'].'/ship', ['remark' => '顺丰 SF123'], $this->adminAuth)
        ->assertJsonPath('code', 0);

    $all = $this->getJson('/api/admin/order-logs?order_no='.$order['order_no'], $this->adminAuth)->json('data.list');
    $shipped = collect($all)->firstWhere('to_status', Order::STATUS_SHIPPED);
    expect($shipped['operator_type'])->toBe(OrderLog::OPERATOR_ADMIN)
        ->and($shipped['remark'])->toBe('顺丰 SF123');

    $byAdmin = $this->getJson('/api/admin/order-logs?operator_type=admin', $this->adminAuth)->json('data.list');
    expect(collect($byAdmin)->pluck('operator_type')->unique()->all())->toBe(['admin']);

    $byUser = $this->getJson('/api/admin/order-logs?operator_type=user', $this->adminAuth)->json('data.list');
    expect($byUser)->not->toBeEmpty()
        ->and(collect($byUser)->pluck('operator_type')->unique()->all())->toBe(['user']);
});

// 按目标状态筛选
test('TC-OLOG-004 按目标状态筛选', function () {
    prepareOrder($this);

    $resp = $this->getJson('/api/admin/order-logs?to_status='.Order::STATUS_PAID, $this->adminAuth);
    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.list'))->not->toBeEmpty()
        ->and(collect($resp->json('data.list'))->pluck('to_status')->unique()->all())->toBe([Order::STATUS_PAID]);

    $this->getJson('/api/admin/order-logs?to_status=not_a_status', $this->adminAuth)->assertStatus(422);
});

// 单笔订单时间轴（正序，含创建记录）
test('TC-OLOG-005 单笔订单时间轴按时间正序', function () {
    [$order] = prepareOrder($this);
    $this->postJson('/api/admin/orders/'.$order['order_id'].'/ship', [], $this->adminAuth)->assertJsonPath('code', 0);

    $resp = $this->getJson('/api/admin/orders/'.$order['order_id'].'/logs', $this->adminAuth);
    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.order_no'))->toBe($order['order_no']);

    $statuses = collect($resp->json('data.list'))->pluck('to_status')->all();
    expect($statuses)->toBe([
        Order::STATUS_PENDING_PAYMENT,
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
    ]);

    // 创建记录 from_status 为空
    expect($resp->json('data.list.0.from_status'))->toBeNull();

    $this->getJson('/api/admin/orders/999999/logs', $this->adminAuth)->assertJsonPath('code', 40004);
});

// 时间区间筛选
test('TC-OLOG-006 按时间区间筛选', function () {
    [$order] = prepareOrder($this);

    $today = now()->format('Y-m-d');
    $resp = $this->getJson('/api/admin/order-logs?start_time='.$today.' 00:00:00&end_time='.$today.' 23:59:59', $this->adminAuth);
    expect($resp->json('data.list'))->not->toBeEmpty();

    $empty = $this->getJson('/api/admin/order-logs?start_time=2020-01-01 00:00:00&end_time=2020-01-02 00:00:00', $this->adminAuth);
    expect($empty->json('data.list'))->toBeEmpty();
});
