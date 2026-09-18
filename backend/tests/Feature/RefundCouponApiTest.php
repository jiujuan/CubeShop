<?php

use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Refund;
use App\Models\UserCoupon;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * V1.1 T-036（F06）：取消/退款与券回退逻辑
 *
 * 覆盖：整单取消券返还（含占用期间过期→expired）、整单全额退款券返还、
 *       部分退款券不返还、退款累计上限校验、重复退款互斥、无券订单退款不变（回归）。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

function t036Buyer(): array
{
    $user = createTestUser('t036');

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('t036')->plainTextToken]];
}

function t036Address(array $auth): int
{
    $addr = test()->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');

    return $addr['id'] ?? $addr;
}

function t036Cart(array $auth, int $skuId, int $qty): void
{
    test()->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->assertOk();
}

function t036Coupon(array $o = []): Coupon
{
    return Coupon::create(array_merge([
        'name' => '券'.uniqid(),
        'type' => Coupon::TYPE_FIXED,
        'amount' => '20.00',
        'min_spend' => '0.00',
        'scope' => Coupon::SCOPE_ALL,
        'scope_refs' => [],
        'total_count' => 100,
        'issued_count' => 1,
        'used_count' => 0,
        'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE,
        'valid_days' => 7,
        'status' => Coupon::STATUS_ACTIVE,
    ], $o));
}

function t036Grant(Coupon $coupon, int $userId, array $o = []): UserCoupon
{
    return UserCoupon::create(array_merge([
        'user_id' => $userId,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ], $o));
}

/** 下单（可带券），返回订单模型 */
function t036Order(array $auth, int $addressId, array $extra = []): Order
{
    $data = test()->postJson('/api/orders', array_merge(['address_id' => $addressId], $extra), $auth)->json('data');

    return Order::find(oid($data['order_id']));
}

/** 沙箱支付成功 → 订单进入 pending_ship（即可退款状态） */
function t036Pay(array $auth, Order $order): void
{
    $payNo = test()->postJson('/api/payments', ['order_no' => $order->order_no, 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');
    test()->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();
}

/** 申请退款，返回退款单 id */
function t036Apply(array $auth, int $orderId, ?string $amount = null): int
{
    $payload = ['reason' => '测试退款'];
    if ($amount !== null) {
        $payload['amount'] = $amount;
    }
    test()->postJson("/api/orders/{$orderId}/refund", $payload, $auth)->assertOk();

    return Refund::where('order_id', oid($orderId))->latest('id')->first()->id;
}

/** 后台审核 */
function t036Process(int $refundId, array $adminAuth, string $action = 'approve'): void
{
    test()->postJson('/api/admin/refunds/'.rfid($refundId).'/process', ['action' => $action], $adminAuth)->assertOk();
}

/* ------------------------------------------------------------------ */
/* 取消 → 券返还                                                         */
/* ------------------------------------------------------------------ */

test('TC-RFD-036-001 未支付取消订单 → 券原样返还且 used_count 回退', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $coupon = t036Coupon(['amount' => '20.00']);
    $uc = t036Grant($coupon, $user->id);
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId, ['user_coupon_id' => $uc->id]);

    // 下单即核销：占用中
    expect($uc->fresh()->status)->toBe(UserCoupon::STATUS_USED)
        ->and((int) $coupon->fresh()->used_count)->toBe(1);

    test()->postJson("/api/orders/{$order->id}/cancel", ['reason' => '不想要了'], $auth)->assertOk();

    $ucFresh = $uc->fresh();
    expect($ucFresh->status)->toBe(UserCoupon::STATUS_UNUSED)
        ->and($ucFresh->used_order_id)->toBeNull()
        ->and($ucFresh->used_at)->toBeNull()
        ->and((int) $coupon->fresh()->used_count)->toBe(0);
});

test('TC-RFD-036-002 券在占用期间过期 → 取消返还为 expired（不复活）', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $coupon = t036Coupon(['amount' => '20.00']);
    $uc = t036Grant($coupon, $user->id);
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId, ['user_coupon_id' => $uc->id]);

    // 模拟占用期间券过期
    DB::table('user_coupons')->where('id', $uc->id)->update(['expire_at' => now()->subDay()]);

    test()->postJson("/api/orders/{$order->id}/cancel", ['reason' => '不想要了'], $auth)->assertOk();

    $ucFresh = $uc->fresh();
    expect($ucFresh->status)->toBe(UserCoupon::STATUS_EXPIRED)
        ->and($ucFresh->used_order_id)->toBeNull()
        // 过期券返还也不应让 used_count 出现负数
        ->and((int) $coupon->fresh()->used_count)->toBe(0);
});

/* ------------------------------------------------------------------ */
/* 退款 → 券回退 / 不回退                                               */
/* ------------------------------------------------------------------ */

test('TC-RFD-036-003 整单全额退款 → 退款金额=行实付Σ+运费，券返还', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00'); // 66×2=132，券20，运费10 → 应付 122
    $coupon = t036Coupon(['amount' => '20.00']);
    $uc = t036Grant($coupon, $user->id);
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId, ['user_coupon_id' => $uc->id]);
    t036Pay($auth, $order);

    expect((string) $order->fresh()->pay_amount)->toBe('122.00');

    $refundId = t036Apply($auth, $order->id); // 不传金额 = 全额
    t036Process($refundId, $this->adminAuth);

    $orderFresh = $order->fresh();
    $refund = Refund::find(rfid($refundId));

    // 退款金额 = 订单实付
    expect((string) $refund->amount)->toBe('122.00')
        ->and($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($orderFresh->status)->toBe(Order::STATUS_REFUNDED)
        // 整单退款 → 券返还
        ->and($uc->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED)
        ->and((int) $coupon->fresh()->used_count)->toBe(0);

    // 不变量：Σ 行实付 + 运费 = 订单实付
    $details = $orderFresh->amount_details;
    $sumPayable = array_sum(array_map(fn ($l) => (float) $l['payable'], $details['lines']));
    expect(round($sumPayable + (float) $details['freight_amount'], 2))->toBe(122.0);
});

test('TC-RFD-036-004 部分退款 → 券不返还，金额正确', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $coupon = t036Coupon(['amount' => '20.00']);
    $uc = t036Grant($coupon, $user->id);
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId, ['user_coupon_id' => $uc->id]);
    t036Pay($auth, $order);

    $refundId = t036Apply($auth, $order->id, '50.00'); // 部分退款
    t036Process($refundId, $this->adminAuth);

    $refund = Refund::find(rfid($refundId));
    expect((string) $refund->amount)->toBe('50.00')
        ->and($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        // 部分退款默认不返还已使用券（防资损）
        ->and($uc->fresh()->status)->toBe(UserCoupon::STATUS_USED)
        ->and((int) $coupon->fresh()->used_count)->toBe(1);
});

/* ------------------------------------------------------------------ */
/* 校验与互斥                                                           */
/* ------------------------------------------------------------------ */

test('TC-RFD-036-005 退款金额超过可退余额被拒', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId);
    t036Pay($auth, $order); // 应付 142（132+10，无券）

    $res = test()->postJson("/api/orders/{$order->id}/refund", [
        'reason' => '贪心退款', 'amount' => '200.00',
    ], $auth);

    $res->assertJsonFragment(['code' => 40000]);
    expect(Refund::where('order_id', oid($order->id))->exists())->toBeFalse();
});

test('TC-RFD-036-006 已存在处理中退款 → 重复申请被拒', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId);
    t036Pay($auth, $order);

    t036Apply($auth, $order->id); // 第一笔（pending）

    $res = test()->postJson("/api/orders/{$order->id}/refund", ['reason' => '再来一笔'], $auth);
    $res->assertJsonFragment(['code' => 40009]); // 冲突：已有退款处理中
    expect(Refund::where('order_id', oid($order->id))->count())->toBe(1);
});

test('TC-RFD-036-007 无券订单退款金额与 V1.0 一致（回归）', function () {
    ['user' => $user, 'auth' => $auth] = t036Buyer();
    $addressId = t036Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00'); // 应付 142（无券）
    t036Cart($auth, $sku->id, 2);

    $order = t036Order($auth, $addressId);
    t036Pay($auth, $order);
    expect((string) $order->fresh()->pay_amount)->toBe('142.00');

    $refundId = t036Apply($auth, $order->id);
    t036Process($refundId, $this->adminAuth);

    $refund = Refund::find(rfid($refundId));
    expect((string) $refund->amount)->toBe('142.00')
        ->and($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and($order->fresh()->coupon_id)->toBeNull();
});
