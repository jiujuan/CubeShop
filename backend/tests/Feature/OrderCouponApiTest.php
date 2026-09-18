<?php

use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\UserCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * V1.1 T-035（F06）：订单金额链路改造与支付回调校验扩展
 *
 * 覆盖：不带券与 V1.0 一致（diff 断言）、带券金额与分摊明细、券+满减叠加、
 *       券不可用各拒绝分支（过期/门槛/范围/他人/已用）、指定与自动匹配满减、
 *       取消返还券与 used_count 回退、列表/详情字段、回调金额不一致被拒、用券支付成功。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
});

/** 造买家 + 授权头 */
function t035Buyer(): array
{
    $user = createTestUser('t035');

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('t035')->plainTextToken]];
}

/** 造收货地址，返回 id */
function t035Address(array $auth): int
{
    $addr = test()->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');

    return $addr['id'] ?? $addr;
}

/** 加入购物车 */
function t035Cart(array $auth, int $skuId, int $qty): void
{
    test()->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->assertOk();
}

/** 造券模板 */
function t035Coupon(array $o = []): Coupon
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

/** 直接给用户发一张券（未使用），返回用户券 */
function t035Grant(Coupon $coupon, int $userId, array $o = []): UserCoupon
{
    return UserCoupon::create(array_merge([
        'user_id' => $userId,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ], $o));
}

/** 造满减活动 */
function t035Promotion(array $o = []): Promotion
{
    return Promotion::create(array_merge([
        'name' => '活动'.uniqid(),
        'rules' => [['min' => 100, 'discount' => 12]],
        'scope' => Promotion::SCOPE_ALL,
        'scope_refs' => [],
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
        'status' => Promotion::STATUS_ACTIVE,
    ], $o));
}

/** 下单（可带券/活动），返回响应 data */
function t035Order(array $auth, int $addressId, array $extra = []): array
{
    return test()->postJson('/api/orders', array_merge(['address_id' => $addressId], $extra), $auth)->json();
}

/* ------------------------------------------------------------------ */
/* 无券路径：与 V1.0 完全一致（关键回归，diff 断言）                        */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-001 不带券下单金额与 V1.0 完全一致（无活动配置）', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00'); // 66×2 = 132，运费 10
    t035Cart($auth, $sku->id, 2);

    $body = t035Order($auth, $addressId);

    expect($body['code'])->toBe(0)
        ->and($body['data']['total_amount'])->toBe('132.00')
        ->and($body['data']['freight_amount'])->toBe('10.00')
        // V1.0 口径：应付 = 商品总额 + 运费，无任何优惠
        ->and($body['data']['pay_amount'])->toBe('142.00')
        ->and($body['data']['discount_amount'])->toBe('0.00')
        ->and($body['data']['promotion_discount'])->toBe('0.00')
        ->and($body['data']['coupon_id'])->toBeNull();

    $details = $body['data']['amount_details'];
    expect($details['v'])->toBe(1)
        ->and($details['goods_amount'])->toBe('132.00')
        ->and($details['discount_amount'])->toBe('0.00')
        ->and($details['pay_amount'])->toBe('142.00')
        ->and($details['lines'])->toHaveCount(1)
        ->and($details['lines'][0]['coupon_share'])->toBe('0.00')
        ->and($details['lines'][0]['promotion_share'])->toBe('0.00');

    // 行项目分摊字段落库为 0
    $item = Order::find(oid($body['data']['order_id']))->items()->first();
    expect((string) $item->coupon_share)->toBe('0.00')
        ->and((string) $item->promotion_share)->toBe('0.00');
});

/* ------------------------------------------------------------------ */
/* 用券下单：金额、分摊、核销落库                                          */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-002 带券下单金额正确且分摊明细与核销完整', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $coupon = t035Coupon(['amount' => '20.00', 'min_spend' => '100.00']);
    $uc = t035Grant($coupon, $user->id);
    t035Cart($auth, $sku->id, 2);

    $body = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id]);

    // 132 − 20 + 10 = 122
    expect($body['code'])->toBe(0)
        ->and($body['data']['discount_amount'])->toBe('20.00')
        ->and($body['data']['pay_amount'])->toBe('122.00')
        ->and($body['data']['coupon_id'])->toBe($coupon->id)
        ->and($body['data']['amount_details']['lines'][0]['coupon_share'])->toBe('20.00');

    $orderId = $body['data']['order_id'];

    // 券被原子核销并绑定订单
    $uc->refresh();
    expect($uc->status)->toBe(UserCoupon::STATUS_USED)
        ->and((int) $uc->used_order_id)->toBe(oid($orderId))
        ->and($uc->used_at)->not->toBeNull();

    // 券模板 used_count +1
    expect((int) $coupon->fresh()->used_count)->toBe(1);

    // 订单行分摊落库
    $item = Order::find(oid($orderId))->items()->first();
    expect((string) $item->coupon_share)->toBe('20.00')
        ->and($item->payableAmount())->toBe(112.0); // 132 − 20
});

test('TC-ORD-035-003 券 + 满减叠加（先满减后券）金额正确', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00'); // 132
    t035Promotion(['rules' => [['min' => 100, 'discount' => 12]]]);
    $coupon = t035Coupon(['type' => Coupon::TYPE_PERCENT, 'amount' => null, 'percent' => 80, 'max_discount' => null]);
    $uc = t035Grant($coupon, $user->id);
    t035Cart($auth, $sku->id, 2);

    $body = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id]);

    // 满减 12 → 120；券 8 折按原始 132 计 = 26.40（≤ 120 余额）
    // 应付 = 132 − 12 − 26.40 + 10 = 103.60
    $d = $body['data']['amount_details'];
    expect($body['data']['promotion_discount'])->toBe('12.00')
        ->and($d['coupon_discount'])->toBe('26.40')
        ->and($d['discount_amount'])->toBe('38.40')
        ->and($d['pay_amount'])->toBe('103.60')
        ->and($d['lines'][0]['promotion_share'])->toBe('12.00')
        ->and($d['lines'][0]['coupon_share'])->toBe('26.40')
        ->and($d['lines'][0]['payable'])->toBe('93.60');
});

/* ------------------------------------------------------------------ */
/* 券不可用：各拒绝分支                                                   */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-004 已过期券下单被拒', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $uc = t035Grant(t035Coupon(), $user->id, ['expire_at' => now()->subMinute()]);
    t035Cart($auth, $sku->id, 2);

    $res = $this->postJson('/api/orders', ['address_id' => $addressId, 'user_coupon_id' => $uc->id], $auth);

    $res->assertStatus(409)->assertJsonPath('code', 40009);
    expect($res->json('message'))->toContain('过期');
    // 未产生订单，券未被占用
    expect(Order::count())->toBe(0)
        ->and($uc->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED);
});

test('TC-ORD-035-005 未达门槛被拒', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $uc = t035Grant(t035Coupon(['min_spend' => '500.00']), $user->id);
    t035Cart($auth, $sku->id, 2);

    $res = $this->postJson('/api/orders', ['address_id' => $addressId, 'user_coupon_id' => $uc->id], $auth);

    $res->assertStatus(409)->assertJsonPath('code', 40009);
    expect($res->json('message'))->toContain('门槛');
    expect(Order::count())->toBe(0);
});

test('TC-ORD-035-006 适用范围不符被拒', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $otherCategory = createTestCategory();
    $uc = t035Grant(
        t035Coupon(['scope' => Coupon::SCOPE_CATEGORY, 'scope_refs' => [$otherCategory]]),
        $user->id,
    );
    t035Cart($auth, $sku->id, 2);

    $res = $this->postJson('/api/orders', ['address_id' => $addressId, 'user_coupon_id' => $uc->id], $auth);

    $res->assertStatus(409)->assertJsonPath('code', 40009);
    expect($res->json('message'))->toContain('适用');
    expect(Order::count())->toBe(0);
});

test('TC-ORD-035-007 他人券按不存在处理（不可枚举）', function () {
    ['auth' => $authA] = t035Buyer();
    ['user' => $userB] = t035Buyer();
    $addressId = t035Address($authA);
    $sku = createTestSku(stock: 10, price: '66.00');
    $ucB = t035Grant(t035Coupon(), $userB->id); // 属于 B
    t035Cart($authA, $sku->id, 2);

    $this->postJson('/api/orders', ['address_id' => $addressId, 'user_coupon_id' => $ucB->id], $authA)
        ->assertStatus(404)->assertJsonPath('code', 40004);

    expect(Order::count())->toBe(0)
        ->and($ucB->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED);
});

test('TC-ORD-035-008 同一张券不能重复使用（一券一单）', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $uc = t035Grant(t035Coupon(), $user->id);

    t035Cart($auth, $sku->id, 2);
    $first = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id]);
    expect($first['code'])->toBe(0);

    // 第二次用同一张券
    t035Cart($auth, $sku->id, 2);
    $second = $this->postJson('/api/orders', ['address_id' => $addressId, 'user_coupon_id' => $uc->id], $auth);

    $second->assertStatus(409)->assertJsonPath('code', 40009);
    expect(Order::count())->toBe(1);
});

/* ------------------------------------------------------------------ */
/* 满减：指定 / 自动匹配最优                                             */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-009 指定满减活动生效且未命中范围被拒', function () {
    ['auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $promotion = t035Promotion(['rules' => [['min' => 100, 'discount' => 12]]]);
    t035Cart($auth, $sku->id, 2);

    $body = t035Order($auth, $addressId, ['promotion_id' => $promotion->id]);

    expect($body['data']['promotion_discount'])->toBe('12.00')
        ->and($body['data']['pay_amount'])->toBe('130.00') // 132 − 12 + 10
        ->and($body['data']['amount_details']['promotion_id'])->toBe($promotion->id);

    // 未开始的活动被拒
    $future = t035Promotion(['start_at' => now()->addDay(), 'end_at' => now()->addDays(2)]);
    t035Cart($auth, $sku->id, 2);
    $this->postJson('/api/orders', ['address_id' => $addressId, 'promotion_id' => $future->id], $auth)
        ->assertStatus(409)->assertJsonPath('code', 40009);

    // 不存在的活动 404
    t035Cart($auth, $sku->id, 2);
    $this->postJson('/api/orders', ['address_id' => $addressId, 'promotion_id' => 999999], $auth)
        ->assertStatus(404)->assertJsonPath('code', 40004);
});

test('TC-ORD-035-010 不传 promotion_id 时自动匹配最优满减（不叠加）', function () {
    ['auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    t035Promotion(['rules' => [['min' => 100, 'discount' => 5]]]);
    $best = t035Promotion(['rules' => [['min' => 100, 'discount' => 30]]]);
    t035Cart($auth, $sku->id, 2);

    $body = t035Order($auth, $addressId);

    // 取优惠最大者（30），不叠加
    expect($body['data']['promotion_discount'])->toBe('30.00')
        ->and($body['data']['amount_details']['promotion_id'])->toBe($best->id)
        ->and($body['data']['pay_amount'])->toBe('112.00'); // 132 − 30 + 10
});

/* ------------------------------------------------------------------ */
/* 取消返还券                                                           */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-011 取消待支付订单返还券并回退 used_count，可再次使用', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $coupon = t035Coupon(['amount' => '20.00']);
    $uc = t035Grant($coupon, $user->id);
    t035Cart($auth, $sku->id, 2);

    $orderId = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id])['data']['order_id'];
    expect((int) $coupon->fresh()->used_count)->toBe(1)
        ->and($uc->fresh()->status)->toBe(UserCoupon::STATUS_USED);

    $this->postJson("/api/orders/{$orderId}/cancel", ['reason' => '不想要了'], $auth)
        ->assertOk()->assertJsonPath('code', 0);

    // 券返还、used_count 回退
    $uc->refresh();
    expect($uc->status)->toBe(UserCoupon::STATUS_UNUSED)
        ->and($uc->used_order_id)->toBeNull()
        ->and($uc->used_at)->toBeNull()
        ->and((int) $coupon->fresh()->used_count)->toBe(0);

    // 返还后仍可正常下单用券
    t035Cart($auth, $sku->id, 2);
    $again = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id]);
    expect($again['code'])->toBe(0)
        ->and($again['data']['discount_amount'])->toBe('20.00');
});

/* ------------------------------------------------------------------ */
/* 列表 / 详情字段                                                       */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-012 订单列表与详情返回优惠汇总与分摊快照', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $uc = t035Grant(t035Coupon(['amount' => '20.00']), $user->id);
    t035Cart($auth, $sku->id, 2);
    $created = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id])['data'];
    $orderId = $created['order_id'];
    $orderNo = $created['order_no'];

    $list = $this->getJson('/api/orders', $auth)->json('data.list');
    // 列表 id 已转为 public_id（P1-5），按稳定的 order_no 关联而非 int 主键
    $row = collect($list)->firstWhere('order_no', $orderNo);
    expect($row)->not->toBeNull()
        ->and($row['discount_amount'])->toBe('20.00')
        ->and($row['promotion_discount'])->toBe('0.00')
        ->and($row['amount_details']['v'])->toBe(1)
        ->and($row['items'][0]['coupon_share'])->toBe('20.00');

    $detail = $this->getJson('/api/orders/'.$orderId, $auth)->json('data');
    expect($detail['discount_amount'])->toBe('20.00')
        ->and($detail['amount_details']['pay_amount'])->toBe('122.00')
        ->and($detail['items'][0]['payable_amount'])->toBe('112.00');
});

/* ------------------------------------------------------------------ */
/* 支付回调金额校验                                                     */
/* ------------------------------------------------------------------ */

test('TC-ORD-035-013 回调金额与订单应付不一致时被拒且不入账', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $uc = t035Grant(t035Coupon(['amount' => '20.00']), $user->id);
    t035Cart($auth, $sku->id, 2);
    $orderNo = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id])['data']['order_no'];

    $payNo = $this->postJson('/api/payments', ['order_no' => $orderNo, 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');

    // 篡改支付单金额（模拟渠道回报低价）→ 与 order 应付不符
    DB::table('payments')->where('payment_no', $payNo)->update(['amount' => '1.00']);

    $res = $this->postJson("/api/payments/sandbox/{$payNo}", [], $auth);
    expect($res->json('data.ok'))->toBeFalse()
        ->and($res->json('data.message'))->toContain('不一致');

    // 未入账：订单仍待支付、支付单仍待处理
    expect(Order::where('order_no', $orderNo)->value('status'))->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and(Payment::where('payment_no', $payNo)->value('status'))->toBe(Payment::STATUS_PENDING)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('locked_stock'))->toBe(2);
});

test('TC-ORD-035-014 用券订单支付成功后状态与金额正确', function () {
    ['user' => $user, 'auth' => $auth] = t035Buyer();
    $addressId = t035Address($auth);
    $sku = createTestSku(stock: 10, price: '66.00');
    $uc = t035Grant(t035Coupon(['amount' => '20.00']), $user->id);
    t035Cart($auth, $sku->id, 2);
    $created = t035Order($auth, $addressId, ['user_coupon_id' => $uc->id])['data'];

    $payNo = $this->postJson('/api/payments', ['order_no' => $created['order_no'], 'channel' => 'wechat'], $auth)
        ->json('data.pay_params.payment_no');

    // 支付单金额 = 优惠后应付
    expect((string) Payment::where('payment_no', $payNo)->value('amount'))->toBe('122.00');

    $this->postJson("/api/payments/sandbox/{$payNo}", [], $auth)->assertOk();

    $order = Order::find(oid($created['order_id']));
    expect($order->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and((string) $order->pay_amount)->toBe('122.00')
        ->and((string) $order->discount_amount)->toBe('20.00')
        ->and((int) Inventory::where('sku_id', $sku->id)->value('stock'))->toBe(8)
        ->and((int) Inventory::where('sku_id', $sku->id)->value('locked_stock'))->toBe(0)
        // 券保持已核销（支付成功后不返还）
        ->and($uc->fresh()->status)->toBe(UserCoupon::STATUS_USED);
});
