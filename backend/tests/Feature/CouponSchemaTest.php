<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\UserCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * T-031（F06）：优惠券/用户券/满减三表 + 订单优惠字段
 *
 * 校验：三表与索引建成、订单/明细新增字段可用、模型关系与状态判定正确、
 * 旧订单数据与旧行为不受影响（新字段为可选/默认值）。
 */
beforeEach(function () {
    seedRoles();
});

test('TC-SCHEMA-031-001 三表建成且索引齐备', function () {
    expect(Schema::hasTable('coupons'))->toBeTrue()
        ->and(Schema::hasTable('user_coupons'))->toBeTrue()
        ->and(Schema::hasTable('promotions'))->toBeTrue();

    foreach (['name', 'type', 'amount', 'percent', 'min_spend', 'max_discount', 'scope', 'scope_refs',
        'total_count', 'issued_count', 'used_count', 'per_user_limit', 'valid_type',
        'valid_from', 'valid_to', 'valid_days', 'status'] as $col) {
        expect(Schema::hasColumn('coupons', $col))->toBeTrue("coupons 缺少列 {$col}");
    }

    foreach (['user_id', 'coupon_id', 'status', 'used_order_id', 'used_at', 'expire_at'] as $col) {
        expect(Schema::hasColumn('user_coupons', $col))->toBeTrue("user_coupons 缺少列 {$col}");
    }

    foreach (['name', 'rules', 'scope', 'scope_refs', 'start_at', 'end_at', 'status'] as $col) {
        expect(Schema::hasColumn('promotions', $col))->toBeTrue("promotions 缺少列 {$col}");
    }
});

test('TC-SCHEMA-031-002 订单与明细新增优惠字段（默认 0 / 可空）', function () {
    foreach (['coupon_id', 'discount_amount', 'promotion_discount', 'amount_details'] as $col) {
        expect(Schema::hasColumn('orders', $col))->toBeTrue("orders 缺少列 {$col}");
    }
    foreach (['coupon_share', 'promotion_share'] as $col) {
        expect(Schema::hasColumn('order_items', $col))->toBeTrue("order_items 缺少列 {$col}");
    }

    $user = createTestUser('schema_user');
    $order = Order::create([
        'order_no' => 'CS-SCHEMA-'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PENDING_PAYMENT,
        'total_amount' => '100.00',
        'freight_amount' => '10.00',
        'pay_amount' => '110.00',
        'address_snapshot' => ['name' => 'x'],
    ]);

    $fresh = $order->fresh();
    expect($fresh->coupon_id)->toBeNull()
        ->and((float) $fresh->discount_amount)->toBe(0.0)
        ->and((float) $fresh->promotion_discount)->toBe(0.0)
        ->and($fresh->amount_details)->toBeNull();
});

test('TC-SCHEMA-031-003 Coupon 模型关系与状态判定', function () {
    $user = createTestUser('coupon_user');
    $coupon = Coupon::create([
        'name' => '满 100 减 10', 'type' => Coupon::TYPE_FIXED, 'amount' => '10.00',
        'min_spend' => '100.00', 'scope' => Coupon::SCOPE_ALL, 'scope_refs' => [],
        'total_count' => 100, 'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE, 'valid_days' => 7, 'status' => Coupon::STATUS_ACTIVE,
    ]);

    UserCoupon::create([
        'user_id' => $user->id, 'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7),
    ]);

    expect($coupon->userCoupons)->toHaveCount(1)
        ->and($coupon->userCoupons->first())->toBeInstanceOf(UserCoupon::class)
        ->and($coupon->scope_refs)->toBe([])
        ->and($coupon->isReceivable())->toBeTrue();

    // 领完不可再领
    $coupon->update(['issued_count' => 100]);
    expect($coupon->fresh()->isReceivable())->toBeFalse();

    // 停发不可领
    $coupon->update(['issued_count' => 0, 'status' => Coupon::STATUS_STOPPED]);
    expect($coupon->fresh()->isReceivable())->toBeFalse();
});

test('TC-SCHEMA-031-004 UserCoupon 状态判定（可用/过期/临近过期）', function () {
    $user = createTestUser('uc_user');
    $coupon = Coupon::create([
        'name' => '券', 'type' => Coupon::TYPE_FIXED, 'amount' => '5.00', 'min_spend' => '0',
        'scope' => Coupon::SCOPE_ALL, 'scope_refs' => [], 'total_count' => 10, 'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE, 'valid_days' => 7, 'status' => Coupon::STATUS_ACTIVE,
    ]);

    $usable = UserCoupon::create([
        'user_id' => $user->id, 'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(30),
    ]);
    $near = UserCoupon::create([
        'user_id' => $user->id, 'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(2),
    ]);
    $expired = UserCoupon::create([
        'user_id' => $user->id, 'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->subDay(),
    ]);

    expect($usable->isUsable())->toBeTrue()
        ->and($usable->isNearExpiry())->toBeFalse()
        ->and($near->isNearExpiry())->toBeTrue()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isUsable())->toBeFalse()
        ->and(UserCoupon::STATUS_LABELS[UserCoupon::STATUS_RETURNED])->toBe('已退回');
});

test('TC-SCHEMA-031-005 Promotion 模型梯度与窗口判定', function () {
    $promo = Promotion::create([
        'name' => '满 200 减 30', 'rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 30]],
        'scope' => Promotion::SCOPE_ALL, 'scope_refs' => [],
        'start_at' => now()->subHour(), 'end_at' => now()->addHour(), 'status' => Promotion::STATUS_ACTIVE,
    ]);

    expect($promo->rules)->toHaveCount(2)
        ->and($promo->rules[1]['discount'])->toBe(30)
        ->and($promo->isRunning())->toBeTrue();

    $promo->update(['status' => Promotion::STATUS_STOPPED]);
    expect($promo->fresh()->isRunning())->toBeFalse();
});

test('TC-SCHEMA-031-006 旧订单数据不受影响（无券订单金额字段为 0/空）', function () {
    $user = createTestUser('legacy_user');
    $order = Order::create([
        'order_no' => 'CS-LEGACY-'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PAID,
        'total_amount' => '50.00',
        'freight_amount' => '0.00',
        'pay_amount' => '50.00',
        'address_snapshot' => ['name' => 'y'],
    ]);

    $row = DB::table('orders')->where('id', $order->id)->first();

    // 旧口径字段不受影响
    expect((float) $row->total_amount)->toBe(50.0)
        ->and((float) $row->pay_amount)->toBe(50.0)
        // 新增字段为默认值，不参与旧计算
        ->and((float) $row->discount_amount)->toBe(0.0)
        ->and((float) $row->promotion_discount)->toBe(0.0)
        ->and($row->amount_details)->toBeNull()
        ->and($row->coupon_id)->toBeNull();
});
