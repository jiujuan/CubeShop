<?php

use App\Models\Promotion;
use App\Services\Marketing\Dto\OrderContext;
use App\Services\Marketing\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-037（F06）：满减匹配与展示数据接口
 *
 * 覆盖：多梯度取最优、刚好门槛、超最高梯度、未达门槛、停发不命中、多活动取最优；
 *       展示数据 current_tier / next_tier / gap_to_next。
 */

function t037Ctx(float $amount, ?array $lines = null): OrderContext
{
    $lines ??= [['product_id' => 1, 'category_id' => 10, 'price' => $amount, 'quantity' => 1]];

    return new OrderContext($lines, 0.0);
}

function t037Promo(array $o = []): Promotion
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

test('多梯度按命中金额取最优梯度（取最大优惠）', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]]]);

    $svc = app(PromotionService::class);
    expect($svc->match(t037Ctx(150.0))->rules[0]['discount'] ?? null)->toBe(10) // 仅够第一档
        ->and($svc->match(t037Ctx(250.0))->rules[1]['discount'] ?? null)->toBe(25); // 命中最高档
});

test('金额刚好等于门槛即命中该梯度', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10]]]);

    $svc = app(PromotionService::class);
    $best = $svc->match(t037Ctx(100.0));

    expect($best)->not->toBeNull()
        ->and($best->rules[0]['discount'])->toBe(10);
});

test('超过最高梯度仍取最高优惠（不溢出）', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]]]);

    $svc = app(PromotionService::class);
    $best = $svc->match(t037Ctx(999.0));

    expect($best)->not->toBeNull()
        ->and($best->rules[1]['discount'])->toBe(25);
});

test('未达最低门槛不命中（返回 null）', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10]]]);

    expect(app(PromotionService::class)->match(t037Ctx(99.0)))->toBeNull();
});

test('已停发活动不参与匹配', function () {
    t037Promo(['status' => Promotion::STATUS_STOPPED]);

    expect(app(PromotionService::class)->match(t037Ctx(500.0)))->toBeNull();
});

test('多活动并存取优惠最大者（不叠加）', function () {
    t037Promo(['name' => 'A', 'rules' => [['min' => 100, 'discount' => 10]]]);
    t037Promo(['name' => 'B', 'rules' => [['min' => 100, 'discount' => 30]]]);

    $best = app(PromotionService::class)->match(t037Ctx(200.0));

    expect($best)->not->toBeNull()
        ->and($best->rules[0]['discount'])->toBe(30); // 取更优，不叠加 10+30
});

test('displayFor 返回当前梯度与下一梯度升级提示', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]]]);

    $d = app(PromotionService::class)->displayFor(t037Ctx(150.0));

    expect($d)->not->toBeNull()
        ->and($d['current_tier'])->toBe(['min' => 100.0, 'discount' => 10.0])
        ->and($d['discount'])->toBe(10.0)
        ->and($d['next_tier'])->toBe(['min' => 200.0, 'discount' => 25.0])
        ->and($d['gap_to_next'])->toBe(50.0);
});

test('displayFor 已命中最高梯度时无下一梯度', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10], ['min' => 200, 'discount' => 25]]]);

    $d = app(PromotionService::class)->displayFor(t037Ctx(300.0));

    expect($d['current_tier']['discount'])->toBe(25.0)
        ->and($d['next_tier'])->toBeNull()
        ->and($d['gap_to_next'])->toBe(0.0);
});

test('displayFor 未命中返回 null', function () {
    t037Promo(['rules' => [['min' => 100, 'discount' => 10]]]);

    expect(app(PromotionService::class)->displayFor(t037Ctx(50.0)))->toBeNull();
});
