<?php

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\UserCoupon;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-034（F06）：用券校验与金额分摊 Service —— DB 边界集成
 *
 * 覆盖：`buildContext` 回库补全分类 id、真实模型下的 `priceOrder` / `validateUse`，
 * 以及 `availableFor` 重构后与 T-033 口径保持一致。
 */
beforeEach(function () {
    seedRoles();
});

function t034Product(int $categoryId, string $price = '100.00'): Product
{
    return Product::create([
        'category_id' => $categoryId,
        'title' => '定价商品'.uniqid(),
        'price' => $price,
        'status' => 1,
    ]);
}

function t034SavedCoupon(array $overrides = []): Coupon
{
    return Coupon::create(array_merge([
        'name' => '定价券'.uniqid(),
        'type' => Coupon::TYPE_FIXED,
        'amount' => '10.00',
        'min_spend' => '0.00',
        'scope' => Coupon::SCOPE_ALL,
        'scope_refs' => [],
        'total_count' => 100,
        'issued_count' => 0,
        'used_count' => 0,
        'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE,
        'valid_days' => 7,
        'status' => Coupon::STATUS_ACTIVE,
    ], $overrides));
}

test('TC-PRC-034-B01 buildContext 按 product_id 回库补全 category_id', function () {
    $categoryId = createTestCategory();
    $product = t034Product($categoryId);

    $ctx = (new CouponService())->buildContext([
        ['product_id' => $product->id, 'price' => '100.00', 'quantity' => 2],
    ]);

    expect($ctx->lines[0]['category_id'])->toBe($categoryId)
        ->and($ctx->lines[0]['amount'])->toBe(200.0)
        ->and($ctx->goodsAmount)->toBe(200.0);
});

test('TC-PRC-034-B02 分类券端到端：命中分类可用且分摊正确', function () {
    $categoryId = createTestCategory();
    $product = t034Product($categoryId);
    $coupon = t034SavedCoupon(['scope' => Coupon::SCOPE_CATEGORY, 'scope_refs' => [$categoryId], 'min_spend' => '50.00']);
    $user = createTestUser('prc_b');

    $uc = UserCoupon::create([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);

    $svc = new CouponService();
    $ctx = $svc->buildContext([['product_id' => $product->id, 'price' => '100.00', 'quantity' => 1]]);

    $svc->validateUse($uc, $ctx); // 不抛异常即通过

    $details = $svc->priceOrder($ctx, $uc);

    expect($details['coupon_discount'])->toBe('10.00')
        ->and($details['pay_amount'])->toBe('90.00')
        ->and($details['coupon_id'])->toBe($coupon->id)
        ->and($details['user_coupon_id'])->toBe($uc->id);
});

test('TC-PRC-034-B03 分类券未命中分类被拒', function () {
    $categoryId = createTestCategory();
    $otherCategory = createTestCategory();
    $product = t034Product($otherCategory);
    $coupon = t034SavedCoupon(['scope' => Coupon::SCOPE_CATEGORY, 'scope_refs' => [$categoryId]]);
    $user = createTestUser('prc_b2');

    $uc = UserCoupon::create([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);

    $svc = new CouponService();
    $ctx = $svc->buildContext([['product_id' => $product->id, 'price' => '100.00', 'quantity' => 1]]);

    expect(fn () => $svc->validateUse($uc, $ctx))
        ->toThrow(BusinessException::class, '订单中没有适用该优惠券的商品');
});

test('TC-PRC-034-B04 availableFor 重构后门槛与范围口径不变', function () {
    $categoryId = createTestCategory();
    $product = t034Product($categoryId);
    $coupon = t034SavedCoupon(['min_spend' => '100.00']);
    $user = createTestUser('prc_b3');

    UserCoupon::create([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);

    $svc = new CouponService();

    // 达标（100 元）→ 可用
    $ok = $svc->availableFor($user->id, [['product_id' => $product->id, 'price' => '100.00', 'quantity' => 1]], 100.0);
    expect($ok['usable'])->toHaveCount(1)
        ->and($ok['unusable'])->toHaveCount(0);

    // 差 1 分 → 不可用且原因正确
    $bad = $svc->availableFor($user->id, [['product_id' => $product->id, 'price' => '99.99', 'quantity' => 1]], 99.99);
    expect($bad['usable'])->toHaveCount(0)
        ->and($bad['unusable'][0]['reason'])->toBe('未满使用门槛');
});

test('TC-PRC-034-B05 availableFor 无行项目时全场券回退到总金额', function () {
    $coupon = t034SavedCoupon(['min_spend' => '50.00']);
    $user = createTestUser('prc_b4');

    UserCoupon::create([
        'user_id' => $user->id,
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ]);

    $res = (new CouponService())->availableFor($user->id, [], 80.0);

    expect($res['usable'])->toHaveCount(1);
});
