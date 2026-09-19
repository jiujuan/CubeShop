<?php

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\UserCoupon;
use App\Services\Marketing\AmountAllocator;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\Dto\OrderContext;
use App\Services\Marketing\PricingCalculator;

/**
 * V1.1 T-034（F06）：用券校验与金额分摊 Service
 *
 * 纯计算链路（无 DB）：
 *   OrderContext + 券参数 + 满减参数 → PricingCalculator::price → amount_details
 * 覆盖：单/多行分摊、尾差归位、percent 封顶、门槛与范围、满减最优梯度、
 *       券+满减叠加、零元行、>10 行、极值、不变量、无券回归口径、
 *       validateUse 各拒绝分支。
 */

/** 构造订单上下文 */
function t034Ctx(array $items, float $freight = 0.0): OrderContext
{
    return new OrderContext($items, $freight);
}

/** 构造券参数（默认：全场满减券直减 10 元） */
function t034Coupon(array $o = []): array
{
    return array_merge([
        'id' => 1,
        'user_coupon_id' => 1,
        'type' => 'fixed',
        'amount' => 10.0,
        'percent' => null,
        'max_discount' => null,
        'min_spend' => 0.0,
        'scope' => 'all',
        'scope_refs' => [],
    ], $o);
}

/** 构造满减活动参数（默认：100-10 / 200-25） */
function t034Promo(array $o = []): array
{
    return array_merge([
        'id' => 1,
        'rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]],
        'scope' => 'all',
        'scope_refs' => [],
    ], $o);
}

/** 构造一个未持久化的用户券（含 coupon 关系），用于 validateUse 分支 */
function t034UserCoupon(array $couponOverrides = [], array $ucOverrides = []): UserCoupon
{
    $coupon = new Coupon(array_merge([
        'name' => '测试券',
        'type' => Coupon::TYPE_FIXED,
        'amount' => '10.00',
        'min_spend' => '0.00',
        'scope' => Coupon::SCOPE_ALL,
        'scope_refs' => [],
        'status' => Coupon::STATUS_ACTIVE,
        'valid_type' => Coupon::VALID_RELATIVE,
        'valid_days' => 7,
    ], $couponOverrides));

    $uc = new UserCoupon(array_merge([
        'user_id' => 1,
        'coupon_id' => 1,
        'status' => UserCoupon::STATUS_UNUSED,
        'expire_at' => now()->addDays(7),
    ], $ucOverrides));
    // 未持久化模型没有自增主键，显式赋 id 以校验 coupon_id / user_coupon_id 透传
    $coupon->id = $couponOverrides['id'] ?? 1;
    $uc->id = $ucOverrides['id'] ?? 1;
    $uc->setRelation('coupon', $coupon);

    return $uc;
}

/** 汇总行分摊 */
function t034Sum(array $details, string $field): float
{
    $sum = 0.0;
    foreach ($details['lines'] as $l) {
        $sum = round($sum + (float) $l[$field], 2);
    }

    return $sum;
}

// ───────────────────────── 券计算 ─────────────────────────

test('TC-PRC-034-001 单行固定券直减并分摊到唯一行', function () {
    $details = PricingCalculator::price(t034Ctx([['price' => 100, 'quantity' => 1]]), t034Coupon());

    expect($details['coupon_discount'])->toBe('10.00')
        ->and($details['discount_amount'])->toBe('10.00')
        ->and($details['goods_amount'])->toBe('100.00')
        ->and($details['pay_amount'])->toBe('90.00')
        ->and($details['lines'][0]['coupon_share'])->toBe('10.00')
        ->and($details['lines'][0]['payable'])->toBe('90.00');
});

test('TC-PRC-034-002 折扣券按比例受 max_discount 封顶', function () {
    // 200 * (100-80)/100 = 40，封顶 30
    $details = PricingCalculator::price(
        t034Ctx([['price' => 200, 'quantity' => 1]]),
        t034Coupon(['type' => 'percent', 'amount' => null, 'percent' => 80, 'max_discount' => 30.0]),
    );

    expect($details['coupon_discount'])->toBe('30.00')
        ->and($details['pay_amount'])->toBe('170.00');
});

test('TC-PRC-034-003 折扣券未触顶按真实折扣', function () {
    // 100 * (100-90)/100 = 10
    $details = PricingCalculator::price(
        t034Ctx([['price' => 100, 'quantity' => 1]]),
        t034Coupon(['type' => 'percent', 'amount' => null, 'percent' => 90]),
    );

    expect($details['coupon_discount'])->toBe('10.00');
});

test('TC-PRC-034-004 多行按行金额占比分摊', function () {
    $details = PricingCalculator::price(
        t034Ctx([['price' => 60, 'quantity' => 1], ['price' => 40, 'quantity' => 1]]),
        t034Coupon(['amount' => 10.0]),
    );

    expect($details['lines'][0]['coupon_share'])->toBe('6.00')
        ->and($details['lines'][1]['coupon_share'])->toBe('4.00')
        ->and(t034Sum($details, 'coupon_share'))->toBe((float) $details['coupon_discount']);
});

test('TC-PRC-034-005 尾差记入金额最大的行（并列取索引最小）', function () {
    // 三行等额 1 元，优惠 1 元：0.33*3 = 0.99，尾差 +0.01 归最大行（并列→索引 0）
    $details = PricingCalculator::price(
        t034Ctx([['price' => 1, 'quantity' => 1], ['price' => 1, 'quantity' => 1], ['price' => 1, 'quantity' => 1]]),
        t034Coupon(['amount' => 1.0]),
    );

    expect($details['coupon_discount'])->toBe('1.00')
        ->and($details['lines'][0]['coupon_share'])->toBe('0.34')
        ->and($details['lines'][1]['coupon_share'])->toBe('0.33')
        ->and($details['lines'][2]['coupon_share'])->toBe('0.33')
        ->and(t034Sum($details, 'coupon_share'))->toBe(1.0);
});

test('TC-PRC-034-006 尾差归金额最大的行（非并列）', function () {
    // 1/3/5 元，优惠 4 元：0.44 + 1.33 + 2.22 = 3.99，尾差 +0.01 归金额最大的第 3 行
    $details = PricingCalculator::price(
        t034Ctx([['price' => 1, 'quantity' => 1], ['price' => 3, 'quantity' => 1], ['price' => 5, 'quantity' => 1]]),
        t034Coupon(['amount' => 4.0]),
    );

    expect($details['lines'][0]['coupon_share'])->toBe('0.44')
        ->and($details['lines'][1]['coupon_share'])->toBe('1.33')
        ->and($details['lines'][2]['coupon_share'])->toBe('2.23')
        ->and(t034Sum($details, 'coupon_share'))->toBe(4.0);
});

test('TC-PRC-034-007 门槛按命中金额判定：刚好达标可用', function () {
    $svc = new CouponService();
    $ctx = t034Ctx([['product_id' => 1, 'category_id' => 1, 'price' => 100, 'quantity' => 1]]);

    $svc->validateUse(t034UserCoupon(['min_spend' => '100.00']), $ctx);

    expect(true)->toBeTrue(); // 未抛异常即通过
});

test('TC-PRC-034-008 门槛差 1 分被拒', function () {
    $svc = new CouponService();
    $ctx = t034Ctx([['product_id' => 1, 'price' => 99.99, 'quantity' => 1]]);

    expect(fn () => $svc->validateUse(t034UserCoupon(['min_spend' => '100.00']), $ctx))
        ->toThrow(BusinessException::class, '未达到优惠券使用门槛');
});

test('TC-PRC-034-009 范围=分类时仅命中分类行参与分摊', function () {
    $details = PricingCalculator::price(
        t034Ctx([
            ['product_id' => 1, 'category_id' => 1, 'price' => 100, 'quantity' => 1],
            ['product_id' => 2, 'category_id' => 2, 'price' => 50, 'quantity' => 1],
        ]),
        t034Coupon(['scope' => 'category', 'scope_refs' => [1], 'amount' => 10.0]),
    );

    expect($details['lines'][0]['coupon_share'])->toBe('10.00')
        ->and($details['lines'][1]['coupon_share'])->toBe('0.00');
});

test('TC-PRC-034-010 范围=商品时仅命中商品行参与分摊', function () {
    $details = PricingCalculator::price(
        t034Ctx([
            ['product_id' => 7, 'price' => 100, 'quantity' => 1],
            ['product_id' => 8, 'price' => 50, 'quantity' => 1],
        ]),
        t034Coupon(['scope' => 'product', 'scope_refs' => [8], 'amount' => 10.0]),
    );

    expect($details['lines'][0]['coupon_share'])->toBe('0.00')
        ->and($details['lines'][1]['coupon_share'])->toBe('10.00');
});

test('TC-PRC-034-011 范围完全不符被拒', function () {
    $svc = new CouponService();
    $ctx = t034Ctx([['product_id' => 7, 'price' => 100, 'quantity' => 1]]);

    expect(fn () => $svc->validateUse(t034UserCoupon(['scope' => Coupon::SCOPE_PRODUCT, 'scope_refs' => [999]]), $ctx))
        ->toThrow(BusinessException::class, '订单中没有适用该优惠券的商品');
});

test('TC-PRC-034-012 已过期券被拒', function () {
    $svc = new CouponService();
    $ctx = t034Ctx([['price' => 100, 'quantity' => 1]]);

    expect(fn () => $svc->validateUse(t034UserCoupon([], ['expire_at' => now()->subDay()]), $ctx))
        ->toThrow(BusinessException::class, '优惠券已过期');
});

test('TC-PRC-034-013 已使用券被拒', function () {
    $svc = new CouponService();
    $ctx = t034Ctx([['price' => 100, 'quantity' => 1]]);

    expect(fn () => $svc->validateUse(t034UserCoupon([], ['status' => UserCoupon::STATUS_USED]), $ctx))
        ->toThrow(BusinessException::class, '优惠券已使用或不可用');
});

test('TC-PRC-034-014 停发券被拒', function () {
    $svc = new CouponService();
    $ctx = t034Ctx([['price' => 100, 'quantity' => 1]]);

    expect(fn () => $svc->validateUse(t034UserCoupon(['status' => Coupon::STATUS_STOPPED]), $ctx))
        ->toThrow(BusinessException::class, '优惠券已停止使用');
});

// ───────────────────────── 满减梯度 ─────────────────────────

test('TC-PRC-034-015 满减取最优梯度', function () {
    expect(PricingCalculator::promotionDiscount([['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]], 250.0))
        ->toBe(25.0);
});

test('TC-PRC-034-016 满减金额刚好等于门槛可命中', function () {
    expect(PricingCalculator::promotionDiscount([['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]], 200.0))
        ->toBe(25.0);
});

test('TC-PRC-034-017 满减超过最高梯度取最高档', function () {
    expect(PricingCalculator::promotionDiscount([['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]], 1000.0))
        ->toBe(25.0);
});

test('TC-PRC-034-018 满减未命中为 0', function () {
    expect(PricingCalculator::promotionDiscount([['min' => 100, 'discount' => 10]], 50.0))->toBe(0.0);
});

test('TC-PRC-034-019 折扣券与满减叠加（先满减后券）', function () {
    // 满减 10 后剩 90；券为 percent 8 折、按原始 100 计算 = 20，封顶至剩余 90 → 仍 20
    $details = PricingCalculator::price(
        t034Ctx([['price' => 100, 'quantity' => 1]]),
        t034Coupon(['type' => 'percent', 'amount' => null, 'percent' => 80]),
        t034Promo(),
    );

    expect($details['promotion_discount'])->toBe('10.00')
        ->and($details['coupon_discount'])->toBe('20.00')
        ->and($details['discount_amount'])->toBe('30.00')
        ->and($details['pay_amount'])->toBe('70.00');
});

// ───────────────────────── 边界与不变量 ─────────────────────────

test('TC-PRC-034-020 两种优惠命中同一行不产生负实付', function () {
    // A 同时命中满减(限商品1)与券(全场)，B 仅命中券；满减吃满 A 后券转由 B 承担
    $details = PricingCalculator::price(
        t034Ctx([
            ['product_id' => 1, 'price' => 50, 'quantity' => 1],
            ['product_id' => 2, 'price' => 50, 'quantity' => 1],
        ]),
        t034Coupon(['amount' => 50.0]),
        t034Promo(['scope' => 'product', 'scope_refs' => [1], 'rules' => [['min' => 0, 'discount' => 50]]]),
    );

    foreach ($details['lines'] as $l) {
        expect((float) $l['payable'])->toBeGreaterThanOrEqual(0.0);
    }
    expect($details['discount_amount'])->toBe('100.00')
        ->and($details['pay_amount'])->toBe('0.00')
        ->and(t034Sum($details, 'promotion_share'))->toBe(50.0)
        ->and(t034Sum($details, 'coupon_share'))->toBe(50.0);
});

test('TC-PRC-034-021 零元赠品行分摊为 0', function () {
    $details = PricingCalculator::price(
        t034Ctx([['price' => 0, 'quantity' => 1], ['price' => 100, 'quantity' => 1]]),
        t034Coupon(['amount' => 10.0]),
    );

    expect($details['lines'][0]['coupon_share'])->toBe('0.00')
        ->and($details['lines'][1]['coupon_share'])->toBe('10.00');
});

test('TC-PRC-034-022 超过 10 行分摊不变量成立', function () {
    $items = [];
    for ($i = 0; $i < 12; $i++) {
        $items[] = ['price' => 10, 'quantity' => 1];
    }
    $details = PricingCalculator::price(t034Ctx($items), t034Coupon(['amount' => 20.0]));

    expect($details['coupon_discount'])->toBe('20.00')
        ->and(t034Sum($details, 'coupon_share'))->toBe(20.0);

    PricingCalculator::assertInvariants($details);
});

test('TC-PRC-034-023 金额极不均衡（1 分与 10000 元并存）无负数', function () {
    $details = PricingCalculator::price(
        t034Ctx([['price' => 0.01, 'quantity' => 1], ['price' => 10000, 'quantity' => 1]]),
        t034Coupon(['amount' => 5000.0]),
    );

    expect($details['coupon_discount'])->toBe('5000.00')
        ->and(t034Sum($details, 'coupon_share'))->toBe(5000.0);

    foreach ($details['lines'] as $l) {
        expect((float) $l['payable'])->toBeGreaterThanOrEqual(0.0)
            ->and((float) $l['coupon_share'])->toBeGreaterThanOrEqual(0.0);
    }

    PricingCalculator::assertInvariants($details);
});

test('TC-PRC-034-024 无券无满减：金额为商品总额加运费（V1.0 回归口径）', function () {
    $details = PricingCalculator::price(t034Ctx([['price' => 100, 'quantity' => 2]], 10.0));

    expect($details['v'])->toBe(PricingCalculator::DETAILS_VERSION)
        ->and($details['goods_amount'])->toBe('200.00')
        ->and($details['freight_amount'])->toBe('10.00')
        ->and($details['coupon_discount'])->toBe('0.00')
        ->and($details['promotion_discount'])->toBe('0.00')
        ->and($details['discount_amount'])->toBe('0.00')
        ->and($details['pay_amount'])->toBe('210.00')
        ->and($details['coupon_id'])->toBeNull()
        ->and($details['promotion_id'])->toBeNull();
});

test('TC-PRC-034-025 amount_details 结构含版本号与固定字段', function () {
    $details = PricingCalculator::price(t034Ctx([['price' => 50, 'quantity' => 1]]));

    expect(array_keys($details))->toBe([
        'v', 'goods_amount', 'freight_amount', 'promotion_discount', 'coupon_discount',
        'discount_amount', 'pay_amount', 'promotion_id', 'coupon_id', 'user_coupon_id',
        'coupon_snapshot', 'promotion_snapshot', 'lines',
    ])->and(array_keys($details['lines'][0]))->toBe([
        'index', 'product_id', 'sku_id', 'amount', 'promotion_share', 'coupon_share', 'freight_share', 'payable',
    ]);
});

test('TC-PRC-034-028 G1 券快照固化名称/面额/类型/门槛', function () {
    $details = PricingCalculator::price(
        t034Ctx([['price' => 100, 'quantity' => 1]]),
        t034Coupon([
            'name' => '新人立减券', 'type' => 'fixed', 'type_label' => '满减券',
            'amount' => 10.0, 'min_spend' => 50.0, 'scope' => 'all', 'scope_label' => '全场',
        ]),
    );

    expect($details['coupon_snapshot'])->not->toBeNull()
        ->and($details['coupon_snapshot']['name'])->toBe('新人立减券')
        ->and($details['coupon_snapshot']['type_label'])->toBe('满减券')
        ->and($details['coupon_snapshot']['amount'])->toBe('10.00')
        ->and($details['coupon_snapshot']['min_spend'])->toBe('50.00')
        ->and($details['coupon_snapshot']['scope_label'])->toBe('全场');
});

test('TC-PRC-034-029 G1 满减快照含命中梯度', function () {
    $details = PricingCalculator::price(
        t034Ctx([['price' => 200, 'quantity' => 1]]),
        null,
        t034Promo(['name' => '年中大促', 'scope' => 'all', 'scope_label' => '全场']),
    );

    expect($details['promotion_snapshot'])->not->toBeNull()
        ->and($details['promotion_snapshot']['name'])->toBe('年中大促')
        ->and($details['promotion_snapshot']['hit_tier'])->toBe(['min' => 200.0, 'discount' => 25.0])
        ->and($details['promotion_discount'])->toBe('25.00');
});

test('TC-PRC-034-030 G3 运费按行实付占比分摊且闭合', function () {
    // 两行券后实付 96.67 / 193.33，运费 30 → 按实付占比分摊 = 10 / 20
    $details = PricingCalculator::price(
        t034Ctx(
            [['price' => 100, 'quantity' => 1], ['price' => 200, 'quantity' => 1]],
            30.0,
        ),
        t034Coupon(['amount' => 10.0]),
    );

    expect($details['lines'][0]['freight_share'])->toBe('10.00')
        ->and($details['lines'][1]['freight_share'])->toBe('20.00')
        ->and((float) $details['lines'][0]['freight_share'] + (float) $details['lines'][1]['freight_share'])->toBe(30.0);

    PricingCalculator::assertInvariants($details);
});

test('TC-PRC-034-031 无券无满减：快照为空、运费分摊到唯一行', function () {
    $details = PricingCalculator::price(t034Ctx([['price' => 100, 'quantity' => 2]], 10.0));

    expect($details['coupon_snapshot'])->toBeNull()
        ->and($details['promotion_snapshot'])->toBeNull()
        ->and($details['lines'][0]['freight_share'])->toBe('10.00');
});

test('TC-PRC-034-026 空购物车仅含运费且不抛错', function () {
    $details = PricingCalculator::price(t034Ctx([], 5.0));

    expect($details['goods_amount'])->toBe('0.00')
        ->and($details['pay_amount'])->toBe('5.00')
        ->and($details['lines'])->toBe([]);
});

test('TC-PRC-034-027 分摊引擎金额最大行并列时尾差归索引最小者', function () {
    $shares = AmountAllocator::allocate(1.0, [0 => 1.0, 1 => 1.0, 2 => 1.0]);

    expect($shares[0])->toBe(0.34)
        ->and($shares[1])->toBe(0.33)
        ->and($shares[2])->toBe(0.33)
        ->and(round(array_sum($shares), 2))->toBe(1.0);
});

test('TC-PRC-034-028 分摊引擎优惠额超过余额时按余额封顶', function () {
    $shares = AmountAllocator::allocate(100.0, [0 => 3.0, 1 => 7.0]);

    expect($shares[0])->toBe(3.0)
        ->and($shares[1])->toBe(7.0)
        ->and(round(array_sum($shares), 2))->toBe(10.0);
});

test('TC-PRC-034-029 叠加顺序常量为先满减后券', function () {
    expect(PricingCalculator::STACK_ORDER)->toBe(['promotion', 'coupon'])
        ->and(PricingCalculator::DETAILS_VERSION)->toBe(1);
});

test('TC-PRC-034-030 CouponService::priceOrder 与纯计算器口径一致', function () {
    $svc = new CouponService();
    $uc = t034UserCoupon(['amount' => '10.00']);
    $promotion = new Promotion([
        'name' => '满减', 'rules' => [['min' => 100, 'discount' => 5]],
        'scope' => 'all', 'scope_refs' => [], 'status' => 'active',
    ]);

    $ctx = t034Ctx([['price' => 100, 'quantity' => 1]]);
    $details = $svc->priceOrder($ctx, $uc, $promotion);

    expect($details['coupon_discount'])->toBe('10.00')
        ->and($details['promotion_discount'])->toBe('5.00')
        ->and($details['pay_amount'])->toBe('85.00')
        ->and($details['user_coupon_id'])->toBe(1)
        ->and($details['coupon_id'])->toBe(1);
});

test('TC-PRC-035-001 recomputePayAmount 与 price 落库的应付一致（口径同源）', function () {
    $ctx = t034Ctx([['price' => 66, 'quantity' => 2]], 10.0);
    $details = PricingCalculator::price($ctx, t034Coupon(['amount' => 20.0]), null);

    $recomputed = PricingCalculator::recomputePayAmount($details);

    expect(number_format($recomputed, 2, '.', ''))->toBe($details['pay_amount'])
        ->and($details['pay_amount'])->toBe('122.00'); // 132 − 20 + 10
});

test('TC-PRC-035-002 recomputePayAmount 随优惠变化，可用于回调篡改检测', function () {
    $base = [
        'goods_amount' => '132.00',
        'freight_amount' => '10.00',
        'discount_amount' => '0.00',
    ];

    expect(PricingCalculator::recomputePayAmount($base))->toBe(142.0);

    // 篡改 discount_amount 后重算必然变化 → 回调校验可据此拒绝
    $tampered = array_merge($base, ['discount_amount' => '132.00']);
    expect(PricingCalculator::recomputePayAmount($tampered))->toBe(10.0)
        ->and(PricingCalculator::recomputePayAmount($tampered))->not->toBe(142.0);
});
