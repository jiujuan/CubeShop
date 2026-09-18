<?php

use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-039（F06）：满减预览接口 GET /promotions/preview
 *
 * 覆盖：命中最优梯度返回展示结构 / 未命中返回 null /
 *       缺 category_id 回库补全仍命中分类活动 / 未登录拒绝。
 */

function t039Auth(): array
{
    $u = createTestUser('t039');

    return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
}

function t039Promo(array $o = []): Promotion
{
    return Promotion::create(array_merge([
        'name' => '满减'.uniqid(),
        'rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]],
        'scope' => Promotion::SCOPE_ALL,
        'scope_refs' => [],
        'start_at' => now()->subDay(),
        'end_at' => now()->addDay(),
        'status' => Promotion::STATUS_ACTIVE,
    ], $o));
}

test('TC-PRV-039-001 命中最优梯度返回展示结构', function () {
    $auth = t039Auth();
    t039Promo();

    $res = $this->getJson('/api/promotions/preview?'.http_build_query([
        'items' => [['product_id' => 1, 'price' => 150, 'quantity' => 1]],
    ]), $auth)->assertOk();

    $promo = $res->json('data.promotion');
    expect($promo)->not->toBeNull()
        ->and($promo['discount'])->toBe(10)
        ->and($promo['current_tier']['min'])->toBe(100)
        ->and($promo['next_tier']['min'])->toBe(200)
        ->and($promo['gap_to_next'])->toBe(50);
});

test('TC-PRV-039-002 未达门槛或活动已结束返回 null', function () {
    $auth = t039Auth();
    // 未达门槛（min=100，金额 50）
    t039Promo();

    $res = $this->getJson('/api/promotions/preview?'.http_build_query([
        'items' => [['product_id' => 1, 'price' => 50, 'quantity' => 1]],
    ]), $auth)->assertOk();

    expect($res->json('data.promotion'))->toBeNull();

    // 已结束活动也不命中
    t039Promo(['rules' => [['min' => 10, 'discount' => 1]], 'end_at' => now()->subHour()]);
    $res2 = $this->getJson('/api/promotions/preview?'.http_build_query([
        'items' => [['product_id' => 1, 'price' => 50, 'quantity' => 1]],
    ]), $auth)->assertOk();

    expect($res2->json('data.promotion'))->toBeNull();
});

test('TC-PRV-039-003 缺 category_id 回库补全后命中分类活动', function () {
    $auth = t039Auth();
    $categoryId = createTestCategory();
    $product = \App\Models\Product::create([
        'category_id' => $categoryId,
        'title' => 'T039商品'.uniqid(),
        'price' => '120.00',
        'status' => 1,
    ]);
    t039Promo(['scope' => Promotion::SCOPE_CATEGORY, 'scope_refs' => [$categoryId]]);

    // 不传 category_id → 由 buildContext 回库补全
    $res = $this->getJson('/api/promotions/preview?'.http_build_query([
        'items' => [['product_id' => $product->id, 'price' => 120, 'quantity' => 1]],
    ]), $auth)->assertOk();

    $promo = $res->json('data.promotion');
    expect($promo)->not->toBeNull()
        ->and($promo['scope'])->toBe('category')
        ->and($promo['discount'])->toBe(10);
});

test('TC-PRV-039-004 未登录拒绝访问', function () {
    $this->getJson('/api/promotions/preview?'.http_build_query([
        'items' => [['product_id' => 1, 'price' => 150, 'quantity' => 1]],
    ]))->assertStatus(401);
});

// ---------- P2-11：行项目 id 为 public_id（结算页入参口径，修复 422） ----------

test('TC-PRV-039-005 product_id 支持 public_id（ULID 字符串）且仍命中分类活动', function () {
    $auth = t039Auth();
    $categoryId = createTestCategory();
    $product = \App\Models\Product::create([
        'category_id' => $categoryId,
        'title' => 'T039商品'.uniqid(),
        'price' => '120.00',
        'status' => 1,
    ]);
    t039Promo(['scope' => Promotion::SCOPE_CATEGORY, 'scope_refs' => [$categoryId]]);

    // 前端结算页传的是商品 public_id 字符串（此前被 validate('integer') 拦成 422）
    $res = $this->getJson('/api/promotions/preview?'.http_build_query([
        'items' => [['product_id' => $product->public_id, 'price' => 120, 'quantity' => 1]],
    ]), $auth)->assertOk();

    $promo = $res->json('data.promotion');
    expect($promo)->not->toBeNull()
        ->and($promo['scope'])->toBe('category')
        ->and($promo['discount'])->toBe(10);
});

