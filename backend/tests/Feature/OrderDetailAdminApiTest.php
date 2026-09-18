<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use App\Models\UserCoupon;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 后台订单详情接口（详情页改造）
 *
 * 覆盖：优惠明细（amount_details / coupon / 行分摊）、收货地址快照、
 *       物流与轨迹时间线、V1.0 老单兼容、鉴权与不存在分支。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class); // T-043 发货需要快递字典

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $this->buyer = createTestUser('detail');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyer->createToken('detail')->plainTextToken];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '张三', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $this->buyerAuth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '66.00');
});

/** 造券模板并直发给买家 */
function detailGrantCoupon(int $userId, array $o = []): UserCoupon
{
    $coupon = Coupon::create(array_merge([
        'name' => '券'.uniqid(),
        'type' => Coupon::TYPE_FIXED,
        'amount' => '20.00',
        'min_spend' => '100.00',
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

    return UserCoupon::create([
        'user_id' => $userId,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);
}

/** 带券下单：66×2 = 132，券减 20，运费 10 → 应付 122 */
function detailCreateOrder($test, ?int $userCouponId = null): int
{
    $test->postJson('/api/cart', ['sku_id' => $test->sku->id, 'quantity' => 2], $test->buyerAuth)->assertOk();

    $payload = ['address_id' => $test->addressId];
    if ($userCouponId) {
        $payload['user_coupon_id'] = $userCouponId;
    }

    $body = $test->postJson('/api/orders', $payload, $test->buyerAuth)->json();

    return oid($body['data']['order_id']);
}

/** 支付并发货，返回 shipping 模型 */
function detailShipOrder($test, int $orderId): Shipping
{
    $order = Order::find(oid($orderId));

    $pay = $test->postJson('/api/payments', [
        'order_no' => $order->order_no, 'channel' => 'wechat',
    ], $test->buyerAuth)->json('data');
    $payNo = $pay['payment_no'] ?? ($pay['pay_params']['payment_no'] ?? null);
    $test->postJson("/api/payments/sandbox/{$payNo}", [], $test->buyerAuth)->assertOk();

    $test->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF',
        'tracking_no' => 'SF'.strtoupper(substr(uniqid(), 0, 12)),
    ], $test->adminAuth)->assertOk();

    return Shipping::where('order_id', oid($orderId))->firstOrFail();
}

/* ------------------------------------------------------------------ */
/* 优惠明细                                                            */
/* ------------------------------------------------------------------ */

test('TC-ORD-DETAIL-001 详情返回优惠明细与行分摊（券 20 / 运费 10）', function () {
    $uc = detailGrantCoupon($this->buyer->id);
    $orderId = detailCreateOrder($this, $uc->id);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    // 订单级优惠字段
    expect($data['discount_amount'])->toBe('20.00')
        ->and($data['promotion_discount'])->toBe('0.00')
        ->and($data['pay_amount'])->toBe('122.00');

    // 金额快照（T-035 唯一口径）
    $details = $data['amount_details'];
    expect($details['v'])->toBe(1)
        ->and($details['goods_amount'])->toBe('132.00')
        ->and($details['freight_amount'])->toBe('10.00')
        ->and($details['coupon_discount'])->toBe('20.00')
        ->and($details['pay_amount'])->toBe('122.00')
        ->and($details['lines'])->toHaveCount(1)
        ->and($details['lines'][0]['coupon_share'])->toBe('20.00');

    // 商品行分摊（曾因 PHP 数组 `+` 左侧优先被静默丢弃，特此锁定）
    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['total_amount'])->toBe('132.00')
        ->and($data['items'][0]['coupon_share'])->toBe('20.00')
        ->and($data['items'][0]['promotion_share'])->toBe('0.00');

    // 券信息
    expect($data['coupon'])->not->toBeNull()
        ->and($data['coupon']['id'])->toBe($uc->coupon_id)
        ->and($data['coupon']['type'])->toBe(Coupon::TYPE_FIXED)
        ->and($data['coupon']['amount'])->toBe('20.00');
});

test('TC-ORD-DETAIL-002 无券订单详情 coupon 为 null 且分摊为 0', function () {
    $orderId = detailCreateOrder($this);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($data['coupon'])->toBeNull()
        ->and($data['discount_amount'])->toBe('0.00')
        ->and($data['items'][0]['coupon_share'])->toBe('0.00')
        ->and($data['items'][0]['promotion_share'])->toBe('0.00')
        ->and($data['amount_details']['coupon_discount'])->toBe('0.00');
});

/* ------------------------------------------------------------------ */
/* 收货地址                                                            */
/* ------------------------------------------------------------------ */

test('TC-ORD-DETAIL-003 详情返回收货地址快照', function () {
    $orderId = detailCreateOrder($this);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    $addr = $data['address_snapshot'];
    expect($addr['contact_name'])->toBe('张三')
        ->and($addr['contact_phone'])->toBe('13800000000')
        ->and($addr['province'])->toBe('广东省')
        ->and($addr['detail_address'])->toBe('科技路 1 号');
});

/* ------------------------------------------------------------------ */
/* 物流与轨迹                                                          */
/* ------------------------------------------------------------------ */

test('TC-ORD-DETAIL-004 发货后详情返回物流信息与轨迹时间线', function () {
    $orderId = detailCreateOrder($this);
    $shipping = detailShipOrder($this, $orderId);

    ShippingTrace::create([
        'shipping_id' => $shipping->id,
        'context' => '已到达【深圳中转中心】',
        'occurred_at' => now()->subHours(2),
    ]);
    ShippingTrace::create([
        'shipping_id' => $shipping->id,
        'context' => '【深圳市】快件已揽收',
        'occurred_at' => now()->subHours(5),
    ]);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($data['shipping'])->toHaveCount(1)
        ->and($data['shipping'][0]['company_code'])->toBe('SF')
        ->and($data['shipping'][0]['company_name'])->not->toBeEmpty()
        ->and($data['shipping'][0]['tracking_no'])->toBe($shipping->tracking_no)
        ->and($data['shipping'][0]['trace_status'])->toBe(Shipping::TRACE_PENDING)
        ->and($data['trace_status'])->toBe(Shipping::TRACE_PENDING);

    // 轨迹按时间倒序（最新在前）
    $traces = $data['shipping'][0]['traces'];
    expect($traces)->toHaveCount(2)
        ->and($traces[0]['context'])->toBe('已到达【深圳中转中心】')
        ->and($traces[1]['context'])->toBe('【深圳市】快件已揽收')
        ->and($traces[0]['occurred_at'])->not->toBeNull();
});

test('TC-ORD-DETAIL-005 未发货订单详情 shipping 为空数组', function () {
    $orderId = detailCreateOrder($this);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($data['shipping'])->toBeArray()
        ->and($data['shipping'])->toHaveCount(0)
        ->and($data['trace_status'])->toBeNull();
});

/* ------------------------------------------------------------------ */
/* V1.0 老单兼容与其他字段                                              */
/* ------------------------------------------------------------------ */

test('TC-ORD-DETAIL-006 无 amount_details 的 V1.0 老单详情不报错', function () {
    $orderId = detailCreateOrder($this);

    // 模拟老单：清空金额快照
    Order::where('id', $orderId)->update(['amount_details' => null]);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($data['amount_details'])->toBeNull()
        ->and($data['pay_amount'])->toBe('142.00')
        ->and($data['items'])->toHaveCount(1);
});

test('TC-ORD-DETAIL-007 详情返回状态时间字段与备注', function () {
    $orderId = detailCreateOrder($this);
    Order::where('id', $orderId)->update(['remark' => '客户要求工作日送达']);

    $data = $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect($data['status'])->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($data['remark'])->toBe('客户要求工作日送达')
        ->and($data)->toHaveKey('paid_at')
        ->and($data)->toHaveKey('shipped_at')
        ->and($data)->toHaveKey('completed_at')
        ->and($data)->toHaveKey('cancelled_at')
        ->and($data)->toHaveKey('cancel_reason')
        ->and($data)->toHaveKey('auto_completed');
});

/* ------------------------------------------------------------------ */
/* 鉴权与异常                                                          */
/* ------------------------------------------------------------------ */

test('TC-ORD-DETAIL-008 未登录访问详情返回 401', function () {
    $orderId = detailCreateOrder($this);

    $this->getJson('/api/admin/orders/'.oid($orderId).'')->assertStatus(401);
});

test('TC-ORD-DETAIL-009 订单不存在返回 404', function () {
    $resp = $this->getJson('/api/admin/orders/99999999', $this->adminAuth);

    expect($resp->status())->toBe(404)
        ->and($resp->json('code'))->not->toBe(0);
});

test('TC-ORD-DETAIL-010 买家不能访问后台详情接口', function () {
    $orderId = detailCreateOrder($this);

    $this->getJson('/api/admin/orders/'.oid($orderId).'', $this->buyerAuth)->assertStatus(403);
});
