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

test('退款概览 stats 返回状态计数/金额、账龄分桶与异常队列', function () {
    // 本地闭包建单（全局函数名须全仓唯一）
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

        return [$user, $svc->transitionTo($order, Order::STATUS_PAID)];
    };

    [$user, $order] = $makePaidOrder();

    $mk = function (array $attrs) use ($user, $order): Refund {
        $r = Refund::create(array_merge([
            'refund_no' => 'RF'.strtoupper(uniqid()),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $user->id,
            'amount' => '0.00',
            'status' => 'pending',
        ], $attrs));

        return $r;
    };
    $age = function (Refund $r, int $hours): Refund {
        $r->forceFill(['created_at' => now()->subHours($hours)])->save();

        return $r->fresh();
    };

    $mk(['amount' => '100.00', 'status' => 'pending']);                                     // pending 2h → lt_24h
    $mk(['amount' => '100.00', 'status' => 'approved', 'type' => 'return_refund',
        'return_status' => 'waiting_return', 'return_details' => [['sku_id' => 1, 'quantity' => 1]]]); // approved 1h → lt_24h
    $mk(['amount' => '50.00', 'status' => 'processing']);                                   // processing 30h → h24_72 + processing_stuck
    $mk(['amount' => '20.00', 'status' => 'success']);                                      // success
    $mk(['amount' => '10.00', 'status' => 'rejected']);                                     // rejected
    $failed = $mk(['amount' => '30.00', 'status' => 'failed', 'retry_count' => 3]);         // failed 100h → gt_72h + failed_maxed
    $returnOverdue = $mk(['amount' => '100.00', 'status' => 'approved', 'type' => 'return_refund',
        'return_status' => 'waiting_return', 'return_details' => [['sku_id' => 1, 'quantity' => 1]]]); // 待退货 8 天 → return_waiting_overdue

    $age($failed, 100);
    $age($returnOverdue, 8 * 24);
    $age(Refund::where('status', 'pending')->first(), 2);
    $age(Refund::where('status', 'processing')->first(), 30);
    $age(Refund::where('status', 'success')->first(), 1);
    $age(Refund::where('status', 'rejected')->first(), 1);
    $approvedFresh = Refund::where('status', 'approved')->where('created_at', '>=', now()->subDays(7))->first();
    $age($approvedFresh, 1);

    $res = $this->getJson('/api/admin/refunds/stats', $this->adminAuth);
    $res->assertOk();
    $data = $res->json('data');

    expect($data['status_counts'])->toBe([
        'pending' => 1, 'approved' => 2, 'processing' => 1, 'success' => 1, 'failed' => 1, 'rejected' => 1,
    ]);
    expect($data['status_amounts']['pending'])->toBe('100.00')
        ->and($data['status_amounts']['approved'])->toBe('200.00')
        ->and($data['status_amounts']['processing'])->toBe('50.00')
        ->and($data['status_amounts']['success'])->toBe('20.00')
        ->and($data['status_amounts']['failed'])->toBe('30.00')
        ->and($data['status_amounts']['rejected'])->toBe('10.00');

    expect($data['aging'])->toBe(['lt_24h' => 2, 'h24_72' => 1, 'gt_72h' => 2]);
    expect($data['queues'])->toBe([
        'processing_stuck' => 1,
        'failed_maxed' => 1,
        'return_waiting_overdue' => 1,
    ]);
});

test('退款列表支持 aged_hours 账龄与 retry_exhausted 重试耗尽筛选', function () {
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

        return [$user, $svc->transitionTo($order, Order::STATUS_PAID)];
    };

    [$user, $order] = $makePaidOrder();

    $mk = function (array $attrs) use ($user, $order): Refund {
        $r = Refund::create(array_merge([
            'refund_no' => 'RF'.strtoupper(uniqid()),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $user->id,
            'amount' => '10.00',
            'status' => 'pending',
        ], $attrs));

        return $r;
    };

    $fresh = $mk(['refund_no' => 'RFRESH1']);                                   // 2h
    $failedMaxed = $mk(['refund_no' => 'RFMAXED1', 'status' => 'failed', 'retry_count' => 3]);
    $failedRetryable = $mk(['refund_no' => 'RFRETRY1', 'status' => 'failed', 'retry_count' => 1]);
    $stale = $mk(['refund_no' => 'RFSTALE1']);                                  // pending 30h
    foreach ([[$fresh, 2], [$failedMaxed, 30], [$failedRetryable, 30], [$stale, 30]] as [$r, $h]) {
        $r->forceFill(['created_at' => now()->subHours($h)])->save();
    }

    // 账龄 ≥24h：failed 两单 + 30h 的 pending
    $aged = $this->getJson('/api/admin/refunds?aged_hours=24', $this->adminAuth)->json('data.list');
    expect($aged)->toHaveCount(3)
        ->and(collect($aged)->pluck('refund_no')->sort()->values()->all())
        ->toBe(['RFMAXED1', 'RFRETRY1', 'RFSTALE1']);

    // 重试耗尽：仅 failed 且 retry_count >= 3
    $maxed = $this->getJson('/api/admin/refunds?status=failed&retry_exhausted=1', $this->adminAuth)->json('data.list');
    expect($maxed)->toHaveCount(1)->and($maxed[0]['refund_no'])->toBe('RFMAXED1');

    // processing 已纳入合法筛选枚举（概览页队列跳转需要）
    $processing = $this->getJson('/api/admin/refunds?status=processing', $this->adminAuth);
    $processing->assertOk()->json('data.list');
    expect($processing->json('data.list'))->toHaveCount(0);
});
