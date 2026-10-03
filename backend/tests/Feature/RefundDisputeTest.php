<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDispute;
use App\Models\RefundLog;
use App\Models\SysUser;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 退款纠纷/申诉（#4）：买家发起、归属校验、后台指派/裁决（含 refund_action 驱动退款状态）、留言。
 * 均用 beforeEach 闭包建数据，避免全局函数重名冲突。
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

    // 注册买家并返回 [authHeader, User]
    $this->registerBuyer = function () {
        $cap = app(CaptchaService::class)->generate();
        $username = 'disp'.uniqid();
        $token = $this->postJson('/api/auth/register', [
            'username' => $username,
            'password' => 'Test@1234',
            'password_confirmation' => 'Test@1234',
            'code' => $cap['debug_code'],
            'captcha_id' => $cap['captcha_id'],
        ])->json('data.token');

        return [['Authorization' => 'Bearer '.$token], User::where('username', $username)->first()];
    };

    // 已支付订单 + 余额成功支付单（供渠道退款终态驱动）
    $this->makePaidOrder = function (int $userId): array {
        $sku = createTestSku(stock: 20, price: '100.00');
        CartItem::create(['user_id' => $userId, 'sku_id' => $sku->id, 'quantity' => 1]);
        $address = UserAddress::create([
            'user_id' => $userId, 'contact_name' => 'a', 'contact_phone' => 'b',
            'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
        ]);
        $svc = app(OrderService::class);
        $order = $svc->createFromCart($userId, $address->id, null, null);
        $order = $svc->transitionTo($order, Order::STATUS_PAID);

        Payment::create([
            'payment_no' => 'PAY'.strtoupper((string) Str::random(16)),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $userId,
            'channel' => Payment::CHANNEL_BALANCE,
            'amount' => $order->pay_amount,
            'status' => Payment::STATUS_SUCCESS,
            'biz_type' => Payment::BIZ_TYPE_ORDER,
            'biz_no' => $order->order_no,
            'paid_at' => now(),
        ]);

        return [$sku, $order];
    };

    // 直接建退款单；$orderToRefunding 时把订单转入 refunding（收货/渠道退款前置状态）
    $this->makeRefund = function (Order $order, int $userId, array $overrides = [], bool $orderToRefunding = false): Refund {
        if ($orderToRefunding) {
            app(OrderService::class)->transitionTo($order, Order::STATUS_REFUNDING);
        }

        return Refund::create(array_merge([
            'refund_no' => 'RF'.strtoupper((string) Str::random(12)),
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'user_id' => $userId,
            'type' => 'refund',
            'amount' => $order->pay_amount,
            'status' => 'pending',
        ], $overrides));
    };
});

test('买家对自己的退款发起纠纷成功并留痕', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id);

    $res = $this->postJson("/api/refunds/{$refund->public_id}/dispute", [
        'reason_code' => 'refund_rejected',
        'description' => '商品无质量问题，要求退款',
    ], $auth)->assertStatus(200);

    expect($res->json('data.status'))->toBe('opened')
        ->and($res->json('data.reason_code'))->toBe('refund_rejected');

    expect(RefundDispute::where('refund_id', $refund->id)->count())->toBe(1)
        ->and(RefundLog::where('refund_id', $refund->id)->where('type', 'dispute_opened')->exists())->toBeTrue();
});

test('越权：不能对他人的退款发起纠纷', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$otherAuth] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id);

    $this->postJson("/api/refunds/{$refund->public_id}/dispute", [
        'reason_code' => 'other',
    ], $otherAuth)->assertStatus(403);
});

test('同一退款仅允许一个进行中的纠纷', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id);

    $this->postJson("/api/refunds/{$refund->public_id}/dispute", ['reason_code' => 'other'], $auth)->assertStatus(200);
    $this->postJson("/api/refunds/{$refund->public_id}/dispute", ['reason_code' => 'other'], $auth)->assertStatus(409);
});

test('success 退款不可再发起纠纷', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id, ['status' => 'success']);

    $this->postJson("/api/refunds/{$refund->public_id}/dispute", ['reason_code' => 'other'], $auth)->assertStatus(400);
});

test('后台指派后纠纷进入已介入', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id);
    $dispute = RefundDispute::create([
        'refund_id' => $refund->id, 'order_id' => $order->id, 'user_id' => $user->id,
        'reason_code' => 'timeout_no_process', 'status' => RefundDispute::STATUS_OPENED,
    ]);

    $adminId = SysUser::where('username', 'admin')->value('id');

    $res = $this->postJson("/api/admin/refund-disputes/{$dispute->id}/assign", ['admin_id' => $adminId], $this->adminAuth)->assertStatus(200);

    expect($res->json('data.status'))->toBe('platform_involved')
        ->and($res->json('data.assignee.id'))->toBe($adminId);
});

test('裁决支持商家不改动退款状态', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id, ['status' => 'rejected']);
    $dispute = RefundDispute::create([
        'refund_id' => $refund->id, 'order_id' => $order->id, 'user_id' => $user->id,
        'reason_code' => 'refund_rejected', 'status' => RefundDispute::STATUS_OPENED,
    ]);

    $res = $this->postJson("/api/admin/refund-disputes/{$dispute->id}/resolve", [
        'resolution' => 'resolved_reject',
        'note' => '商品质检无问题，维持拒绝结论',
    ], $this->adminAuth)->assertStatus(200);

    expect($res->json('data.status'))->toBe('resolved_reject')
        ->and($refund->fresh()->status)->toBe('rejected');
});

test('裁决重新发起审核：rejected → pending 且订单回 refunding', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id, ['status' => 'rejected']);
    $dispute = RefundDispute::create([
        'refund_id' => $refund->id, 'order_id' => $order->id, 'user_id' => $user->id,
        'reason_code' => 'refund_rejected', 'status' => RefundDispute::STATUS_OPENED,
    ]);

    $res = $this->postJson("/api/admin/refund-disputes/{$dispute->id}/resolve", [
        'resolution' => 'resolved_refund',
        'refund_action' => 're_open_refund',
        'note' => '平台核实支持买家',
    ], $this->adminAuth)->assertStatus(200);

    expect($res->json('data.status'))->toBe('resolved_refund')
        ->and($refund->fresh()->status)->toBe('pending')
        ->and($order->fresh()->status)->toBe('refunding');
});

test('裁决同意退款：return_refund pending → approved 待收货', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id, ['type' => 'return_refund', 'status' => 'pending']);
    $dispute = RefundDispute::create([
        'refund_id' => $refund->id, 'order_id' => $order->id, 'user_id' => $user->id,
        'reason_code' => 'timeout_no_process', 'status' => RefundDispute::STATUS_OPENED,
    ]);

    $res = $this->postJson("/api/admin/refund-disputes/{$dispute->id}/resolve", [
        'resolution' => 'resolved_refund',
        'refund_action' => 'approve_refund',
    ], $this->adminAuth)->assertStatus(200);

    expect($refund->fresh()->status)->toBe('approved')
        ->and($refund->fresh()->return_status)->toBe('waiting_return');
});

test('裁决强制收货：良品全收 → 退款成功', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id, [
        'type' => 'return_refund',
        'status' => 'approved',
        'return_status' => 'waiting_return',
        'return_details' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ], orderToRefunding: true);
    $dispute = RefundDispute::create([
        'refund_id' => $refund->id, 'order_id' => $order->id, 'user_id' => $user->id,
        'reason_code' => 'goods_damaged_dispute', 'status' => RefundDispute::STATUS_OPENED,
    ]);

    $res = $this->postJson("/api/admin/refund-disputes/{$dispute->id}/resolve", [
        'resolution' => 'resolved_refund',
        'refund_action' => 'force_receive',
        'note' => '买家举证充分，按良品全收处理',
    ], $this->adminAuth)->assertStatus(200);

    expect($refund->fresh()->status)->toBe('success')
        ->and($refund->fresh()->return_status)->toBe('received');
});

test('纠纷留言：买家发起、后台可见', function () {
    [$auth, $user] = ($this->registerBuyer)();
    [$sku, $order] = ($this->makePaidOrder)($user->id);
    $refund = ($this->makeRefund)($order, $user->id);
    $dispute = RefundDispute::create([
        'refund_id' => $refund->id, 'order_id' => $order->id, 'user_id' => $user->id,
        'reason_code' => 'other', 'status' => RefundDispute::STATUS_OPENED,
    ]);

    $this->postJson("/api/refunds/{$refund->public_id}/disputes/{$dispute->public_id}/messages", [
        'body' => '请尽快处理',
    ], $auth)->assertStatus(200);

    $list = $this->getJson("/api/admin/refund-disputes/{$dispute->id}/messages", $this->adminAuth)->assertStatus(200)->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0]['sender_type'])->toBe('customer')
        ->and($list[0]['body'])->toBe('请尽快处理');
});
