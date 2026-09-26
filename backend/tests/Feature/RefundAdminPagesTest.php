<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\UserAddress;
use App\Models\PaymentReconciliationRun;
use App\Models\PaymentReconciliationDiff;
use App\Services\Common\CaptchaService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

test('退款列表支持 type / return_status 筛选', function () {
    // 本地闭包建单，避免与 RefundReconcileTest 的全局函数冲突
    $makePaidOrder = function (): array {
        $user = createTestUser();
        $sku = createTestSku(stock: 20, price: '100.00');
        CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
        $address = UserAddress::create([
            'user_id' => $user->id, 'contact_name' => 'a', 'contact_phone' => 'b',
            'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
        ]);
        $svc = app(OrderService::class);
        $order = $svc->createFromCart($user->id, $address->id, null, null);
        $order = $svc->transitionTo($order, Order::STATUS_PAID);

        return [$user, $order];
    };

    [$user, $order] = $makePaidOrder();

    $return = Refund::create([
        'refund_no' => 'RF'.strtoupper(uniqid()),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => 'return_refund',
        'amount' => '100.00',
        'status' => 'approved',
        'return_status' => 'waiting_return',
        'return_details' => [['sku_id' => 1, 'quantity' => 1]],
    ]);
    $plain = Refund::create([
        'refund_no' => 'RF'.strtoupper(uniqid()),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => 'refund',
        'amount' => '100.00',
        'status' => 'pending',
    ]);

    $all = $this->getJson('/api/admin/refunds', $this->adminAuth)->json('data.list');
    expect($all)->toHaveCount(2);

    $onlyReturn = $this->getJson('/api/admin/refunds?type=return_refund', $this->adminAuth)->json('data.list');
    expect($onlyReturn)->toHaveCount(1)->and($onlyReturn[0]['id'])->toBe($return->id);

    $byReturnStatus = $this->getJson('/api/admin/refunds?return_status=waiting_return', $this->adminAuth)->json('data.list');
    expect($byReturnStatus)->toHaveCount(1)->and($byReturnStatus[0]['id'])->toBe($return->id);
});

test('对账差异支持 category=refund / payment 过滤', function () {
    $run = PaymentReconciliationRun::create([
        'reconcile_date' => now()->toDateString(),
        'channel' => 'wechat',
    ]);
    $refundDiff = PaymentReconciliationDiff::create([
        'run_id' => $run->id,
        'reconcile_date' => now()->toDateString(),
        'channel' => 'wechat',
        'diff_type' => PaymentReconciliationDiff::TYPE_REFUND_STATUS_MISMATCH,
        'payment_no' => 'RFX1',
        'channel_trade_no' => 'OUT1',
        'status' => 'pending',
    ]);
    $payDiff = PaymentReconciliationDiff::create([
        'run_id' => $run->id,
        'reconcile_date' => now()->toDateString(),
        'channel' => 'wechat',
        'diff_type' => PaymentReconciliationDiff::TYPE_MISSING_LOCAL,
        'payment_no' => 'PAY1',
        'channel_trade_no' => 'CT1',
        'status' => 'pending',
    ]);

    $refundOnly = $this->getJson('/api/admin/payment-reconcile-diffs?category=refund', $this->adminAuth)->json('data.list');
    expect($refundOnly)->toHaveCount(1)->and($refundOnly[0]['id'])->toBe($refundDiff->id);

    $payOnly = $this->getJson('/api/admin/payment-reconcile-diffs?category=payment', $this->adminAuth)->json('data.list');
    expect($payOnly)->toHaveCount(1)->and($payOnly[0]['id'])->toBe($payDiff->id);
});
