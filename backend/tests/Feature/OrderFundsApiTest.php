<?php

use App\Models\Order;
use App\Models\Refund;
use App\Services\Common\CaptchaService;
use App\Services\Payment\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 后台订单资金视图（G7：统一资金流水聚合）
 *
 * GET /admin/orders/{id}/funds（权限 order.view）
 *
 * 覆盖：余额支付全链路（支付单 + 余额消费流水 + 退款 + 余额退回流水）的聚合正确性、
 * 无支付订单的空态、鉴权（无权限 403 / 订单不存在 404）。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $this->buyer = createTestUser('funds');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyer->createToken('funds')->plainTextToken];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '张三', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $this->buyerAuth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '30.00');
});

/** 下单（30×2=60，运费 10 → 应付 70），可选余额支付，返回订单 int id */
function fundsCreateOrder($test, bool $payByBalance = false): int
{
    $test->postJson('/api/cart', ['sku_id' => $test->sku->id, 'quantity' => 2], $test->buyerAuth)->assertOk();
    $order = $test->postJson('/api/orders', ['address_id' => $test->addressId], $test->buyerAuth)->json('data');
    $orderId = oid($order['order_id']);

    if ($payByBalance) {
        app(BalanceService::class)->credit($test->buyer->id, '500.00');
        $test->postJson('/api/payments', [
            'order_no' => $order['order_no'], 'channel' => 'balance',
        ], $test->buyerAuth)->assertOk();
    }

    return $orderId;
}

/** 买家提交全额仅退款并由管理员同意 → 退款成功（余额支付时退回余额） */
function fundsRefundSuccess($test, int $orderId): Refund
{
    $order = Order::find($orderId);
    $test->postJson("/api/orders/{$orderId}/refund", ['reason' => '不想要了'], $test->buyerAuth)->assertOk();

    $refund = Refund::where('order_id', $orderId)->firstOrFail();
    $test->postJson("/api/admin/refunds/{$refund->id}/process", ['action' => 'approve'], $test->adminAuth)->assertOk();

    return $refund->fresh();
}

test('TC-FUNDS-001 余额支付全链路聚合：支付单/事件/余额流水/退款/汇总', function () {
    $orderId = fundsCreateOrder($this, payByBalance: true);
    fundsRefundSuccess($this, $orderId);

    $res = $this->getJson("/api/admin/orders/{$orderId}/funds", $this->adminAuth);
    $res->assertOk();
    $data = $res->json('data');

    // 订单块：金额快照随行
    expect($data['order']['order_no'])->toBe(Order::find($orderId)->order_no)
        ->and($data['order']['pay_amount'])->toBe('70.00')
        ->and($data['order']['amount_details'])->not->toBeNull()
        ->and($data['order']['amount_details']['pay_amount'])->toBe('70.00');

    // 支付单：1 笔余额支付成功
    expect($data['payments'])->toHaveCount(1)
        ->and($data['payments'][0]['channel'])->toBe('balance')
        ->and($data['payments'][0]['channel_label'])->toBe('余额支付')
        ->and($data['payments'][0]['status'])->toBe('success')
        ->and($data['payments'][0]['amount'])->toBe('70.00')
        ->and($data['payments'][0]['paid_at'])->not->toBeNull();

    // 支付事件：至少包含创建（BalanceGateway 同步扣款，无渠道回调）
    $events = collect($data['payment_events']);
    expect($events->pluck('event'))->toContain('create')
        ->and($events->every(fn ($e) => $e['payment_no'] === $data['payments'][0]['payment_no']))->toBeTrue();

    // 余额流水：消费 −70（余额退回流水仅在退款走余额网关时产生；当前沙箱审核直接置成功、不经网关）
    $logs = collect($data['balance_logs']);
    $consume = $logs->firstWhere('type', 'consume');
    expect($consume)->not->toBeNull()
        ->and($consume['amount'])->toBe('-70.00')
        ->and($consume['balance_before'])->toBe('500.00')
        ->and($consume['balance_after'])->toBe('430.00')
        ->and($logs->firstWhere('type', 'refund'))->toBeNull();

    // 退款单：成功 + 优惠构成快照随行
    expect($data['refunds'])->toHaveCount(1)
        ->and($data['refunds'][0]['status'])->toBe('success')
        ->and($data['refunds'][0]['status_label'])->toBe('退款成功')
        ->and($data['refunds'][0]['amount'])->toBe('70.00')
        ->and($data['refunds'][0]['refund_details'])->not->toBeNull()
        ->and($data['refunds'][0]['refund_details']['pay_amount'])->toBe('70.00');

    // 汇总：收款 70、退款 70、余额消费 70、净入账 0
    expect($data['summary']['pay_success_amount'])->toBe('70.00')
        ->and($data['summary']['refund_success_amount'])->toBe('70.00')
        ->and($data['summary']['balance_consume_amount'])->toBe('70.00')
        ->and($data['summary']['balance_refund_amount'])->toBe('0.00')
        ->and($data['summary']['net_amount'])->toBe('0.00');
});

test('TC-FUNDS-002 无支付订单返回空聚合与零汇总（amount_details 快照仍随行）', function () {
    $orderId = fundsCreateOrder($this);

    $data = $this->getJson("/api/admin/orders/{$orderId}/funds", $this->adminAuth)->assertOk()->json('data');

    expect($data['payments'])->toBe([])
        ->and($data['payment_events'])->toBe([])
        ->and($data['balance_logs'])->toBe([])
        ->and($data['refunds'])->toBe([])
        ->and($data['order']['amount_details'])->not->toBeNull()
        ->and($data['summary'])->toEqual([
            'pay_success_amount' => '0.00',
            'refund_success_amount' => '0.00',
            'balance_consume_amount' => '0.00',
            'balance_refund_amount' => '0.00',
            'net_amount' => '0.00',
        ]);
});

test('TC-FUNDS-003 部分退款：净入账 = 收款 − 退款', function () {
    $orderId = fundsCreateOrder($this, payByBalance: true);

    $order = Order::find($orderId);
    $this->postJson("/api/orders/{$orderId}/refund", [
        'reason' => '部分退款', 'amount' => '10.00',
    ], $this->buyerAuth)->assertOk();
    $refund = Refund::where('order_id', $orderId)->firstOrFail();
    $this->postJson("/api/admin/refunds/{$refund->id}/process", ['action' => 'approve'], $this->adminAuth)->assertOk();

    $data = $this->getJson("/api/admin/orders/{$orderId}/funds", $this->adminAuth)->assertOk()->json('data');

    expect($data['summary']['pay_success_amount'])->toBe('70.00')
        ->and($data['summary']['refund_success_amount'])->toBe('10.00')
        ->and($data['summary']['net_amount'])->toBe('60.00');
});

test('TC-FUNDS-004 无 order.view 权限访问 403，订单不存在 404', function () {
    $orderId = fundsCreateOrder($this);

    $this->getJson("/api/admin/orders/{$orderId}/funds", $this->buyerAuth)->assertStatus(403);
    $this->getJson('/api/admin/orders/999999/funds', $this->adminAuth)->assertStatus(404);
});
