<?php

use App\Models\Coupon;
use App\Models\UserCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * V1.1 T-033（F06）：领券接口（原子防超发）与我的券接口
 *
 * 覆盖：领取成功/限领/领完/停发、到期时间固化（绝对/相对）、我的券状态筛选、
 *       可用券及其不可用原因、过期任务、未登录 401。
 */
beforeEach(function () {
    seedRoles();
});

/** 造一张可领券 */
function makeCoupon(array $overrides = []): Coupon
{
    return Coupon::create(array_merge([
        'name' => '券'.uniqid(),
        'type' => Coupon::TYPE_FIXED,
        'amount' => '10.00',
        'min_spend' => '0.00',
        'scope' => Coupon::SCOPE_ALL,
        'scope_refs' => [],
        'total_count' => 10,
        'issued_count' => 0,
        'used_count' => 0,
        'per_user_limit' => 1,
        'valid_type' => Coupon::VALID_RELATIVE,
        'valid_days' => 7,
        'status' => Coupon::STATUS_ACTIVE,
    ], $overrides));
}

function buyerAuth(): array
{
    $u = createTestUser('coupon_rcv');

    return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
}

test('TC-CPN-033-001 领取成功并返回券记录', function () {
    $coupon = makeCoupon();
    $auth = buyerAuth();

    $res = $this->postJson("/api/coupons/{$coupon->id}/receive", [], $auth)
        ->assertOk()->assertJsonPath('code', 0);

    expect($coupon->fresh()->issued_count)->toBe(1)
        ->and($res->json('data.user_coupon_id'))->toBeInt();

    $this->assertDatabaseHas('user_coupons', [
        'coupon_id' => $coupon->id,
        'status' => UserCoupon::STATUS_UNUSED,
    ]);
});

test('TC-CPN-033-002 达每人限领被拒', function () {
    $coupon = makeCoupon(['per_user_limit' => 1]);
    $auth = buyerAuth();

    $this->postJson("/api/coupons/{$coupon->id}/receive", [], $auth)->assertOk();
    $this->postJson("/api/coupons/{$coupon->id}/receive", [], $auth)
        ->assertStatus(409)->assertJsonPath('code', 40009);

    expect($coupon->fresh()->issued_count)->toBe(1);
});

test('TC-CPN-033-003 领完后被拒（issued_count 不超发）', function () {
    $coupon = makeCoupon(['total_count' => 1, 'per_user_limit' => 5]);

    $this->postJson("/api/coupons/{$coupon->id}/receive", [], buyerAuth())->assertOk();
    // 第二位用户来领
    $this->postJson("/api/coupons/{$coupon->id}/receive", [], buyerAuth())
        ->assertStatus(409)->assertJsonPath('code', 40009);

    expect($coupon->fresh()->issued_count)->toBe(1)
        ->and(UserCoupon::where('coupon_id', $coupon->id)->count())->toBe(1);
});

test('TC-CPN-033-004 停发的券不可领', function () {
    $coupon = makeCoupon(['status' => Coupon::STATUS_STOPPED]);

    $this->postJson("/api/coupons/{$coupon->id}/receive", [], buyerAuth())
        ->assertStatus(409)->assertJsonPath('code', 40009);
});

test('TC-CPN-033-005 未登录领取 401', function () {
    $coupon = makeCoupon();

    $this->postJson("/api/coupons/{$coupon->id}/receive")->assertStatus(401);
});

test('TC-CPN-033-006 expire_at 固化：相对有效期按领取时间计算', function () {
    $coupon = makeCoupon(['valid_type' => Coupon::VALID_RELATIVE, 'valid_days' => 5]);

    $this->postJson("/api/coupons/{$coupon->id}/receive", [], buyerAuth())->assertOk();

    $uc = UserCoupon::where('coupon_id', $coupon->id)->first();
    expect($uc->expire_at->format('Y-m-d'))->toBe(now()->addDays(5)->format('Y-m-d'));
});

test('TC-CPN-033-007 expire_at 固化：绝对有效期取券的 valid_to', function () {
    $coupon = makeCoupon([
        'valid_type' => Coupon::VALID_ABSOLUTE,
        'valid_days' => null,
        'valid_from' => now()->subDay(),
        'valid_to' => now()->addDays(20),
    ]);

    $this->postJson("/api/coupons/{$coupon->id}/receive", [], buyerAuth())->assertOk();

    $uc = UserCoupon::where('coupon_id', $coupon->id)->first();
    expect($uc->expire_at->format('Y-m-d'))->toBe(now()->addDays(20)->format('Y-m-d'));
});

test('TC-CPN-033-008 已过期的绝对券不可领', function () {
    $coupon = makeCoupon([
        'valid_type' => Coupon::VALID_ABSOLUTE,
        'valid_days' => null,
        'valid_from' => now()->subDays(10),
        'valid_to' => now()->subDay(),
    ]);

    $this->postJson("/api/coupons/{$coupon->id}/receive", [], buyerAuth())
        ->assertStatus(409)->assertJsonPath('code', 40009);
});

test('TC-CPN-033-009 我的券按状态筛选正确', function () {
    $u = createTestUser('my_coupons');
    $auth = ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    $coupon = makeCoupon(['total_count' => 100, 'per_user_limit' => 10]);

    // 未使用（有效）
    UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $coupon->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(3)]);
    // 未使用但已过期（任务未收敛）
    UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $coupon->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->subDay()]);
    // 已使用
    UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $coupon->id, 'status' => UserCoupon::STATUS_USED, 'expire_at' => now()->addDays(5), 'used_at' => now()]);

    $unused = $this->getJson('/api/me/coupons?status=unused', $auth)->json('data.list');
    $expired = $this->getJson('/api/me/coupons?status=expired', $auth)->json('data.list');
    $used = $this->getJson('/api/me/coupons?status=used', $auth)->json('data.list');

    expect(count($unused))->toBe(1)
        ->and($unused[0]['near_expiry'])->toBeTrue()      // ≤3 天
        ->and(count($expired))->toBe(1)
        ->and($expired[0]['status'])->toBe('expired')     // 未收敛的过期券也归入 expired
        ->and(count($used))->toBe(1);
});

test('TC-CPN-033-010 可用券接口：门槛不足与范围不符给原因', function () {
    $u = createTestUser('avail_user');
    $auth = ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];

    $ok = makeCoupon(['min_spend' => '50.00']);
    $short = makeCoupon(['min_spend' => '500.00']);
    $scoped = makeCoupon(['scope' => Coupon::SCOPE_PRODUCT, 'scope_refs' => [999999]]);

    foreach ([$ok, $short, $scoped] as $c) {
        UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $c->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7)]);
    }

    $res = $this->getJson('/api/coupons/available?amount=100', $auth)->assertOk();

    $usable = collect($res->json('data.usable'));
    $unusable = collect($res->json('data.unusable'));

    expect($usable)->toHaveCount(1)
        ->and($usable->first()['coupon_id'])->toBe($ok->id);
    expect($unusable->pluck('reason')->all())
        ->toContain('未满使用门槛')
        ->toContain('适用范围不符');
});

test('TC-CPN-033-011 可用券：指定商品范围按命中金额判定', function () {
    $sku = createTestSku(stock: 10, price: '60.00');
    $productId = $sku->product_id;

    $u = createTestUser('avail_scope');
    $auth = ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];

    $hit = makeCoupon(['scope' => Coupon::SCOPE_PRODUCT, 'scope_refs' => [$productId], 'min_spend' => '50.00']);
    $miss = makeCoupon(['scope' => Coupon::SCOPE_PRODUCT, 'scope_refs' => [$productId + 100000], 'min_spend' => '50.00']);

    foreach ([$hit, $miss] as $c) {
        UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $c->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7)]);
    }

    $res = $this->getJson("/api/coupons/available?amount=60&items[0][product_id]={$productId}&items[0][price]=60&items[0][quantity]=1", $auth)->assertOk();

    expect(collect($res->json('data.usable'))->pluck('coupon_id')->all())->toBe([$hit->id]);
});

// ---------- P2-11：items 行项目 id 为 public_id（结算页入参口径，修复 422） ----------

test('TC-CPN-033-015 可用券：items product_id 支持 public_id（ULID 字符串）入参', function () {
    $sku = createTestSku(stock: 10, price: '60.00');
    $product = $sku->product;
    $productId = $sku->product_id;

    $u = createTestUser('avail_pubid');
    $auth = ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];

    $hit = makeCoupon(['scope' => Coupon::SCOPE_PRODUCT, 'scope_refs' => [$productId], 'min_spend' => '50.00']);
    UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $hit->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDays(7)]);

    // 前端结算页传的是商品 public_id 字符串（此前被 validate('integer') 拦成 422）
    $res = $this->getJson("/api/coupons/available?amount=60&items[0][product_id]={$product->public_id}&items[0][price]=60&items[0][quantity]=1", $auth)->assertOk();

    expect(collect($res->json('data.usable'))->pluck('coupon_id')->all())->toBe([$hit->id]);
});

test('TC-CPN-033-012 领券中心：登录后附带个人领取状态', function () {
    $coupon = makeCoupon();
    $auth = buyerAuth();
    $this->postJson("/api/coupons/{$coupon->id}/receive", [], $auth)->assertOk();

    // 未登录：只有券面
    $guest = $this->getJson('/api/coupons')->assertOk()->json('data.list');
    expect($guest[0]['received_by_me'])->toBe(0);

    // 登录：已领 1 张，达到限领
    $mine = $this->getJson('/api/coupons', $auth)->assertOk()->json('data.list');
    expect($mine[0]['received_by_me'])->toBe(1)
        ->and($mine[0]['can_receive'])->toBeFalse();
});

test('TC-CPN-033-013 过期任务：unused 且过期 → expired', function () {
    $coupon = makeCoupon(['total_count' => 100]);
    $u = createTestUser('expire_user');

    $stale = UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $coupon->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->subHour()]);
    $fresh = UserCoupon::create(['user_id' => $u->id, 'coupon_id' => $coupon->id, 'status' => UserCoupon::STATUS_UNUSED, 'expire_at' => now()->addDay()]);

    $this->artisan('coupons:expire')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(UserCoupon::STATUS_EXPIRED)
        ->and($fresh->fresh()->status)->toBe(UserCoupon::STATUS_UNUSED);
});

test('TC-CPN-033-014 领取与 issued_count 一致（无记录行数漂移）', function () {
    $coupon = makeCoupon(['total_count' => 5, 'per_user_limit' => 1]);
    foreach (range(1, 3) as $i) {
        $u = createTestUser('c'.$i);
        $this->postJson("/api/coupons/{$coupon->id}/receive", [], ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken])->assertOk();
    }

    $issued = DB::table('coupons')->where('id', $coupon->id)->value('issued_count');
    $records = DB::table('user_coupons')->where('coupon_id', $coupon->id)->count();

    expect((int) $issued)->toBe(3)
        ->and($records)->toBe(3);
});
