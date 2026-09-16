<?php

use App\Models\Coupon;
use App\Models\UserCoupon;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\PromotionService;
use App\Services\Marketing\Dto\OrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * V1.1 T-037（F06）：券过期收敛 + 过期/停发对可用券列表的影响
 *
 * 覆盖：coupons:expire 过期未用券置 expired（计数正确、已用/未过期不动）；
 *       可用券列表排除 expired 与 stopped 模板券（移入 unusable）；
 *       停发活动不再被 match 命中。
 */

function t037User(): int
{
    return createTestUser('t037')->id;
}

function t037Coupon(array $o = []): Coupon
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
        'valid_type' => Coupon::VALID_ABSOLUTE,
        'valid_from' => now()->subDay(),
        'valid_to' => now()->addDays(7),
        'status' => Coupon::STATUS_ACTIVE,
    ], $o));
}

function t037Grant(int $userId, int $couponId, array $o = []): UserCoupon
{
    return UserCoupon::create(array_merge([
        'user_id' => $userId,
        'coupon_id' => $couponId,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ], $o));
}

test('TC-EXP-037-001 coupons:expire 将过期未用券置 expired 且计数正确', function () {
    $userId = t037User();
    $coupon = t037Coupon();

    // 3 张已过期未用
    foreach (range(1, 3) as $_) {
        t037Grant($userId, $coupon->id, ['expire_at' => now()->subDay()]);
    }
    // 1 张未过期（应保留）
    $keep = t037Grant($userId, $coupon->id, ['expire_at' => now()->addDay()]);
    // 1 张已用（不应被动）
    $used = t037Grant($userId, $coupon->id, ['expire_at' => now()->subDay(), 'status' => UserCoupon::STATUS_USED]);

    // expireOverdue 一次性将 3 张过期未用券置 expired，并返回处理数量
    $count = app(CouponService::class)->expireOverdue();

    expect($count)->toBe(3)
        ->and(UserCoupon::where('status', UserCoupon::STATUS_EXPIRED)->where('id', '<>', $used->id)->count())->toBe(3)
        ->and($keep->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED)
        ->and($used->fresh()->status)->toBe(UserCoupon::STATUS_USED);

    // 命令 coupons:expire 仅委托给 expireOverdue，在当前（已无待过期）状态下幂等返回成功码
    expect(Artisan::call('coupons:expire'))->toBe(0);
});

test('TC-EXP-037-002 expireOverdue 对已用/未过期券无副作用', function () {
    $userId = t037User();
    $coupon = t037Coupon();

    $unusedFresh = t037Grant($userId, $coupon->id, ['expire_at' => now()->addDays(3)]);
    $used = t037Grant($userId, $coupon->id, ['expire_at' => now()->addDays(3), 'status' => UserCoupon::STATUS_USED]);

    $n = app(CouponService::class)->expireOverdue();

    expect($n)->toBe(0)
        ->and($unusedFresh->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED)
        ->and($used->fresh()->status)->toBe(UserCoupon::STATUS_USED);
});

test('TC-EXP-037-003 可用券列表排除已过期券（移入 unusable）', function () {
    $userId = t037User();
    $coupon = t037Coupon();
    t037Grant($userId, $coupon->id, ['expire_at' => now()->subDay()]); // 已过期

    $result = app(CouponService::class)->availableFor($userId, [], 200.0);

    expect($result['usable'])->toBeEmpty()
        ->and(collect($result['unusable'])->contains('reason', '已过期'))->toBeTrue();
});

test('TC-EXP-037-004 可用券列表排除已停发券模板（移入 unusable）', function () {
    $userId = t037User();
    $coupon = t037Coupon(['status' => Coupon::STATUS_STOPPED]);
    t037Grant($userId, $coupon->id, ['expire_at' => now()->addDays(7)]); // 未过期但模板停发

    $result = app(CouponService::class)->availableFor($userId, [], 200.0);

    expect($result['usable'])->toBeEmpty()
        ->and(collect($result['unusable'])->contains('reason', '优惠券已停止使用'))->toBeTrue();
});

test('TC-EXP-037-005 活动停发后不再被匹配命中', function () {
    $promo = \App\Models\Promotion::create([
        'name' => '满100减10',
        'rules' => [['min' => 100, 'discount' => 10]],
        'scope' => \App\Models\Promotion::SCOPE_ALL,
        'scope_refs' => [],
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
        'status' => \App\Models\Promotion::STATUS_ACTIVE,
    ]);
    $ctx = new OrderContext([['product_id' => 1, 'category_id' => 10, 'price' => 150.0, 'quantity' => 1]], 0.0);

    expect(app(PromotionService::class)->match($ctx))->not->toBeNull()
        ->and(app(PromotionService::class)->displayFor($ctx))->not->toBeNull();

    $promo->update(['status' => \App\Models\Promotion::STATUS_STOPPED]);

    expect(app(PromotionService::class)->match($ctx))->toBeNull()
        ->and(app(PromotionService::class)->displayFor($ctx))->toBeNull();
});
