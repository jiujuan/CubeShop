<?php

use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => 'cartuser'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$this->token];
    $this->sku = createTestSku(stock: 10, price: '25.50');
});

function addToCart($test, array $auth, int $skuId, int $qty): array
{
    return $test->postJson('/api/cart', ['sku_id' => $skuId, 'quantity' => $qty], $auth)->json();
}

// CART-001 加入购物车
test('TC-CART-001 加入购物车成功', function () {
    $body = addToCart($this, $this->auth, $this->sku->id, 2);

    expect($body['code'])->toBe(0);

    $items = $this->getJson('/api/cart', $this->auth)->json('data.items');
    expect($items)->toHaveCount(1)->and((int) $items[0]['quantity'])->toBe(2);
});

test('重复加购同一 SKU 合并数量', function () {
    addToCart($this, $this->auth, $this->sku->id, 1);
    addToCart($this, $this->auth, $this->sku->id, 2);

    $list = $this->getJson('/api/cart', $this->auth)->json('data.items');
    expect(count($list))->toBe(1)->and((int) $list[0]['quantity'])->toBe(3);
});

// CART-002 超库存加购拒绝
test('TC-CART-002 超库存加购被拒绝', function () {
    $body = addToCart($this, $this->auth, $this->sku->id, 11);

    expect($body['code'])->not->toBe(0);
});

// CART-003 修改数量
test('TC-CART-003 修改数量生效', function () {
    addToCart($this, $this->auth, $this->sku->id, 1);
    $itemId = $this->getJson('/api/cart', $this->auth)->json('data.items.0.id');

    $resp = $this->putJson("/api/cart/{$itemId}", ['quantity' => 5], $this->auth);

    $updated = collect($this->getJson('/api/cart', $this->auth)->json('data.items'))
        ->firstWhere('id', $itemId);
    expect($resp->json('code'))->toBe(0)
        ->and((int) ($updated['quantity'] ?? 0))->toBe(5);
});

// CART-004 删除（按 sku 定位）
test('TC-CART-004 删除购物车商品', function () {
    $skuB = createTestSku(stock: 5);
    addToCart($this, $this->auth, $this->sku->id, 1);
    addToCart($this, $this->auth, $skuB->id, 1);

    $items = $this->getJson('/api/cart', $this->auth)->json('data.items');
    $targetId = collect($items)->firstWhere('sku_id', $skuB->id)['id'];

    $resp = $this->deleteJson("/api/cart/{$targetId}", [], $this->auth);

    $remaining = $this->getJson('/api/cart', $this->auth)->json('data.items');
    expect($resp->json('code'))->toBe(0)
        ->and(count($remaining))->toBe(1)
        ->and($remaining[0]['sku_id'])->toBe($this->sku->id);
});

// CART-005 数量非法
test('TC-CART-005 数量小于 1 被拒绝', function () {
    $body = addToCart($this, $this->auth, $this->sku->id, 0);

    expect($body['code'])->not->toBe(0);
});

// 购物车隔离：他人购物车不可见
test('购物车按用户隔离', function () {
    addToCart($this, $this->auth, $this->sku->id, 1);

    $cap = app(CaptchaService::class)->generate();
    $otherToken = $this->postJson('/api/auth/register', [
        'username' => 'othercart'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');

    $otherCart = $this->getJson('/api/cart', ['Authorization' => 'Bearer '.$otherToken])->json('data.items');
    expect($otherCart)->toBeEmpty();
});
