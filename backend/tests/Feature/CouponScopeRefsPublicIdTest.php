<?php

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\UserCoupon;
use App\Services\Common\CaptchaService;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * P2-11 归一化：优惠券 scope_refs 统一采用 public_id（ULID）。
 *
 * 覆盖：admin 入参兼容 int 主键与 public_id 并归一化存储为 public_id；
 *       前台领券中心出口为 public_id（供详情页 scope_refs.includes(category.id) 匹配）；
 *       public_id 存储下分类券命中/未命中口径不变。
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

/** 构造固定面额券创建载荷（避免依赖其它文件中的全局函数） */
function pidCouponPayload(array $overrides = []): array
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

/** 创建商品（对齐 CouponPricingServiceTest::t034Product，避免跨文件全局函数依赖） */
function pidProduct(int $categoryId, string $price = '100.00'): Product
{
    return Product::create([
        'category_id' => $categoryId,
        'title' => '定价商品'.uniqid(),
        'price' => $price,
        'status' => 1,
    ]);
}

test('TC-PID-051-001 admin 接受 public_id 入参并存储为 public_id', function () {
    $cat = Category::find(createTestCategory());

    $res = $this->postJson('/api/admin/coupons', pidCouponPayload([
        'scope' => 'category', 'scope_refs' => [$cat->public_id], 'total_count' => 10,
    ]), $this->adminAuth);

    $res->assertOk()->assertJsonPath('code', 0);
    $coupon = Coupon::find($res->json('data.id'));

    expect($coupon->scope_refs)->toBe([$cat->public_id])
        ->and($coupon->scope_refs[0])->toBeString();
});

test('TC-PID-051-002 admin 接受历史 int 入参并归一化为 public_id（向后兼容）', function () {
    $cat = Category::find(createTestCategory());

    $res = $this->postJson('/api/admin/coupons', pidCouponPayload([
        'scope' => 'category', 'scope_refs' => [$cat->id], 'total_count' => 10,
    ]), $this->adminAuth);

    $res->assertOk()->assertJsonPath('code', 0);
    $coupon = Coupon::find($res->json('data.id'));

    expect($coupon->scope_refs)->toBe([$cat->public_id]);
});

test('TC-PID-051-003 前台领券中心出口 scope_refs 为 public_id', function () {
    $cat = Category::find(createTestCategory());
    $name = 'PID-券-'.uniqid();

    $this->postJson('/api/admin/coupons', pidCouponPayload([
        'name' => $name, 'scope' => 'category', 'scope_refs' => [$cat->public_id], 'total_count' => 10,
    ]), $this->adminAuth)->assertOk();

    $list = $this->getJson('/api/coupons')->json('data.list');
    $row = collect($list)->first(fn ($c) => $c['name'] === $name);

    expect($row)->not->toBeNull()
        ->and($row['scope_refs'])->toBe([$cat->public_id])
        ->and($row['scope_refs'][0])->toBeString();
});

test('TC-PID-051-004 public_id 存储下分类券命中/未命中口径不变', function () {
    $catHit = Category::find(createTestCategory());
    $catMiss = Category::find(createTestCategory());
    $product = pidProduct($catHit->id);
    $user = createTestUser('pid_u');

    // 命中分类（scope_refs 用 public_id 存储）
    $coupon = Coupon::find($this->postJson('/api/admin/coupons', pidCouponPayload([
        'scope' => 'category', 'scope_refs' => [$catHit->public_id], 'min_spend' => '50.00', 'total_count' => 10,
    ]), $this->adminAuth)->json('data.id'));

    $uc = UserCoupon::create([
        'user_id' => $user->id, 'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7),
    ]);

    $svc = new CouponService();
    $ctxHit = $svc->buildContext([['product_id' => $product->id, 'price' => '100.00', 'quantity' => 1]]);
    $svc->validateUse($uc, $ctxHit); // 命中不抛异常

    $ctxMiss = $svc->buildContext([['product_id' => pidProduct($catMiss->id)->id, 'price' => '100.00', 'quantity' => 1]]);
    expect(fn () => $svc->validateUse($uc, $ctxMiss))
        ->toThrow(BusinessException::class, '订单中没有适用该优惠券的商品');
});
