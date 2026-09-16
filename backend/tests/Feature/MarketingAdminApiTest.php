<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\UserCoupon;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-032（F06）：优惠券与满减活动管理接口
 *
 * 覆盖：券 CRUD、参数与范围校验、已发放券核心字段保护、停止发放、
 *       统计核对、满减梯度校验与启停、权限/鉴权。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $capOp = app(CaptchaService::class)->generate();
    $this->operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator', 'password' => 'Operator@123',
        'captcha_id' => $capOp['captcha_id'], 'captcha_code' => $capOp['debug_code'],
    ])->json('data.token')];
});

/** 构造固定面额券的创建载荷 */
function fixedCouponPayload(array $overrides = []): array
{
    return array_merge([
        'name' => '满100减10-'.uniqid(),
        'type' => 'fixed',
        'amount' => 10,
        'min_spend' => 100,
        'scope' => 'all',
        'total_count' => 100,
        'per_user_limit' => 1,
        'valid_type' => 'relative',
        'valid_days' => 7,
    ], $overrides);
}

test('TC-MKT-032-001 创建固定面额券成功并落库', function () {
    $res = $this->postJson('/api/admin/coupons', fixedCouponPayload(['name' => '国庆券']), $this->operatorAuth);

    $res->assertOk()->assertJsonPath('code', 0);
    $id = $res->json('data.id');

    $coupon = Coupon::find($id);
    expect($coupon)->not->toBeNull()
        ->and($coupon->type)->toBe(Coupon::TYPE_FIXED)
        ->and((float) $coupon->amount)->toBe(10.0)
        ->and($coupon->percent)->toBeNull()
        ->and($coupon->issued_count)->toBe(0)
        ->and($coupon->status)->toBe(Coupon::STATUS_ACTIVE);
});

test('TC-MKT-032-002 创建折扣券成功且面额字段被清空', function () {
    $res = $this->postJson('/api/admin/coupons', [
        'name' => '八折券', 'type' => 'percent', 'percent' => 80, 'max_discount' => 50,
        'min_spend' => 0, 'scope' => 'all', 'total_count' => 50, 'per_user_limit' => 2,
        'valid_type' => 'relative', 'valid_days' => 30,
    ], $this->adminAuth)->assertOk();

    $coupon = Coupon::find($res->json('data.id'));
    expect($coupon->type)->toBe(Coupon::TYPE_PERCENT)
        ->and($coupon->percent)->toBe(80)
        ->and((float) $coupon->max_discount)->toBe(50.0)
        ->and($coupon->amount)->toBeNull();
});

test('TC-MKT-032-003 券名重复返回业务冲突', function () {
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['name' => '重复券']), $this->adminAuth)->assertOk();
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['name' => '重复券']), $this->adminAuth)
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);
});

test('TC-MKT-032-004 非法参数返回 422 / 业务码', function () {
    // 面额非正
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['amount' => 0]), $this->adminAuth)->assertStatus(422);
    // 折扣越界
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['type' => 'percent', 'percent' => 100, 'amount' => null]), $this->adminAuth)->assertStatus(422);
    // 发放总量为 0
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['total_count' => 0]), $this->adminAuth)->assertStatus(422);
    // 顺延有效期缺天数
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['valid_type' => 'relative', 'valid_days' => null]), $this->adminAuth)->assertStatus(422);
    // 范围限定但未选对象 → 业务码 40000
    $res = $this->postJson('/api/admin/coupons', fixedCouponPayload(['scope' => 'product', 'scope_refs' => []]), $this->adminAuth);
    $res->assertStatus(400)->assertJsonPath('code', 40000);
});

test('TC-MKT-032-005 适用范围 id 不存在被拒', function () {
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['scope' => 'product', 'scope_refs' => [999999]]), $this->operatorAuth)
        ->assertStatus(400)
        ->assertJsonPath('code', 40000);
});

test('TC-MKT-032-006 绝对有效期起止校验', function () {
    // 结束早于开始
    $this->postJson('/api/admin/coupons', fixedCouponPayload([
        'valid_type' => 'absolute', 'valid_from' => '2026-10-10 00:00:00', 'valid_to' => '2026-10-01 00:00:00',
    ]), $this->adminAuth)->assertStatus(400)->assertJsonPath('code', 40000);
});

test('TC-MKT-032-007 已发放券不可改面额/门槛，可改名称与延长有效期', function () {
    $id = $this->postJson('/api/admin/coupons', fixedCouponPayload([
        'valid_type' => 'absolute', 'valid_from' => '2026-01-01 00:00:00', 'valid_to' => '2026-12-31 00:00:00',
    ]), $this->adminAuth)->json('data.id');

    Coupon::find($id)->update(['issued_count' => 5]); // 模拟已发放

    // 改面额 → 拦截
    $this->putJson("/api/admin/coupons/{$id}", ['amount' => 20], $this->adminAuth)
        ->assertStatus(409)->assertJsonPath('code', 40009);
    // 改门槛 → 拦截
    $this->putJson("/api/admin/coupons/{$id}", ['min_spend' => 200], $this->adminAuth)
        ->assertStatus(409)->assertJsonPath('code', 40009);
    // 缩短有效期 → 拦截
    $this->putJson("/api/admin/coupons/{$id}", ['valid_to' => '2026-06-30 00:00:00'], $this->adminAuth)
        ->assertStatus(409)->assertJsonPath('code', 40009);

    // 改名称 + 延长有效期 → 允许
    $this->putJson("/api/admin/coupons/{$id}", ['name' => '改名后的券', 'valid_to' => '2027-06-30 00:00:00'], $this->adminAuth)
        ->assertOk()->assertJsonPath('code', 0);

    $coupon = Coupon::find($id);
    expect($coupon->name)->toBe('改名后的券')
        ->and((float) $coupon->amount)->toBe(10.0)          // 面额未被改动
        ->and($coupon->valid_to->format('Y-m-d'))->toBe('2027-06-30');
});

test('TC-MKT-032-008 停止发放后状态为 stopped', function () {
    $id = $this->postJson('/api/admin/coupons', fixedCouponPayload(), $this->operatorAuth)->json('data.id');

    $this->postJson("/api/admin/coupons/{$id}/stop", [], $this->operatorAuth)->assertOk();
    expect(Coupon::find($id)->status)->toBe(Coupon::STATUS_STOPPED);
});

test('TC-MKT-032-009 统计数字与手算一致', function () {
    $id = $this->postJson('/api/admin/coupons', fixedCouponPayload(['total_count' => 10]), $this->adminAuth)->json('data.id');
    $coupon = Coupon::find($id);

    // 3 人领取，其中 1 人核销
    $buyers = [];
    foreach (range(1, 3) as $i) {
        $buyers[] = createTestUser('coupon_buyer_'.$i);
    }
    foreach ($buyers as $b) {
        UserCoupon::create(['user_id' => $b->id, 'coupon_id' => $id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7)]);
    }
    UserCoupon::where('coupon_id', $id)->orderBy('id')->first()->update([
        'status' => UserCoupon::STATUS_USED, 'used_at' => now(),
    ]);
    $coupon->update(['issued_count' => 3, 'used_count' => 1]);

    // 一笔用券订单
    Order::create([
        'order_no' => 'CS-MKT-'.uniqid(), 'user_id' => $buyers[0]->id, 'status' => Order::STATUS_PAID,
        'total_amount' => '100.00', 'freight_amount' => '0.00', 'pay_amount' => '90.00',
        'coupon_id' => $id, 'discount_amount' => '10.00', 'address_snapshot' => ['name' => 'x'],
    ]);

    $res = $this->getJson("/api/admin/coupons/{$id}/stats", $this->adminAuth)->assertOk();

    expect($res->json('data.total_count'))->toBe(10)
        ->and($res->json('data.received_count'))->toBe(3)
        ->and($res->json('data.used_count'))->toBe(1)
        ->and($res->json('data.available_count'))->toBe(7)
        ->and($res->json('data.order_count'))->toBe(1)
        ->and((float) $res->json('data.discount_sum'))->toBe(10.0)
        ->and((float) $res->json('data.order_amount_sum'))->toBe(90.0);
});

test('TC-MKT-032-010 满减活动创建：梯度必须递增且不重复', function () {
    $base = [
        'name' => '满减活动', 'scope' => 'all',
        'start_at' => '2026-01-01 00:00:00', 'end_at' => '2026-12-31 00:00:00',
    ];

    // 正常
    $this->postJson('/api/admin/promotions', $base + [
        'rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 30]],
    ], $this->operatorAuth)->assertOk();

    // 非递增
    $this->postJson('/api/admin/promotions', $base + ['name' => '乱序', 'rules' => [['min' => 200, 'discount' => 10], ['min' => 100, 'discount' => 30]]], $this->adminAuth)
        ->assertStatus(400)->assertJsonPath('code', 40000);
    // 门槛重复
    $this->postJson('/api/admin/promotions', $base + ['name' => '重复', 'rules' => [['min' => 100, 'discount' => 10], ['min' => 100, 'discount' => 20]]], $this->adminAuth)
        ->assertStatus(400)->assertJsonPath('code', 40000);
    // 折扣非正
    $this->postJson('/api/admin/promotions', $base + ['name' => '零折扣', 'rules' => [['min' => 100, 'discount' => 0]]], $this->adminAuth)
        ->assertStatus(422);
});

test('TC-MKT-032-011 满减活动启停切换', function () {
    $id = $this->postJson('/api/admin/promotions', [
        'name' => '启停活动', 'scope' => 'all', 'rules' => [['min' => 100, 'discount' => 10]],
        'start_at' => '2026-01-01 00:00:00', 'end_at' => '2026-12-31 00:00:00',
    ], $this->adminAuth)->json('data.id');

    expect(Promotion::find($id)->status)->toBe(Promotion::STATUS_ACTIVE);

    $this->postJson("/api/admin/promotions/{$id}/toggle", [], $this->adminAuth)
        ->assertOk()->assertJsonPath('data.status', Promotion::STATUS_STOPPED);
    $this->postJson("/api/admin/promotions/{$id}/toggle", [], $this->adminAuth)
        ->assertOk()->assertJsonPath('data.status', Promotion::STATUS_ACTIVE);
});

test('TC-MKT-032-012 券列表筛选与分页', function () {
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['name' => '筛选A']), $this->adminAuth);
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['name' => '筛选B', 'type' => 'percent', 'percent' => 90, 'amount' => null]), $this->adminAuth);

    $res = $this->getJson('/api/admin/coupons?type=percent', $this->adminAuth)->assertOk();
    $list = $res->json('data.list');
    expect(count($list))->toBe(1)
        ->and($list[0]['name'])->toBe('筛选B')
        ->and($res->json('data.pagination.total'))->toBe(1);
});

test('TC-MKT-032-013 买家访问营销管理接口 403，未登录 401', function () {
    $buyer = createTestUser('mkt_buyer');
    $buyerAuth = ['Authorization' => 'Bearer '.$buyer->createToken('t')->plainTextToken];

    $this->getJson('/api/admin/coupons', $buyerAuth)->assertStatus(403);
    $this->getJson('/api/admin/coupons')->assertStatus(401);
});

test('TC-MKT-032-014 写操作记入操作日志', function () {
    $this->postJson('/api/admin/coupons', fixedCouponPayload(['name' => '日志券']), $this->adminAuth)->assertOk();

    $this->assertDatabaseHas('sys_operation_log', [
        'module' => 'coupon',
        'action' => 'create',
    ]);
});
