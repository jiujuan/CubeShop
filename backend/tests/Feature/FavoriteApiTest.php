<?php

use App\Models\BrowseHistory;
use App\Models\Favorite;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Common\CaptchaService;
use App\Services\Favorite\FavoriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-024：收藏与浏览足迹接口
 *
 * 覆盖：收藏幂等/取消/批量、失效标记、足迹去重与上限、数据隔离、未登录 401。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->auth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'favbuyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];
    $this->userId = $this->getJson('/api/auth/me', $this->auth)->json('data.id');

    $this->sku = createTestSku(stock: 10, price: '58.00');
    $this->productId = $this->sku->product_id;
});

// ---------- 收藏 ----------

test('TC-FAV-001 收藏幂等：重复收藏仅一条记录', function () {
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth)->assertOk();
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth)->assertOk();

    expect(Favorite::where('user_id', $this->userId)->where('product_id', $this->productId)->count())->toBe(1);
});

test('TC-FAV-002 取消收藏（重复取消也返回成功）', function () {
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);
    $this->deleteJson("/api/products/{$this->productId}/favorite", [], $this->auth)->assertOk();
    $this->deleteJson("/api/products/{$this->productId}/favorite", [], $this->auth)->assertOk();

    expect(Favorite::where('user_id', $this->userId)->count())->toBe(0);
});

test('TC-FAV-003 收藏不存在的商品返回 404', function () {
    $resp = $this->postJson('/api/products/99999999/favorite', [], $this->auth)->json();
    expect($resp['code'])->toBe(40004);
});

test('TC-FAV-004 收藏列表返回商品字段与失效标记', function () {
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);

    $resp = $this->getJson('/api/me/favorites', $this->auth)->json();
    expect($resp['code'])->toBe(0)
        ->and($resp['data']['list'])->toHaveCount(1)
        ->and($resp['data']['list'][0]['id'])->toBe($this->productId)
        ->and($resp['data']['list'][0]['is_available'])->toBeTrue()
        ->and($resp['data']['list'][0]['price'])->toBe('58.00');
});

test('TC-FAV-005 下架商品在收藏列表标记为不可用', function () {
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);
    Product::whereKey($this->productId)->update(['status' => 0]);

    $item = $this->getJson('/api/me/favorites', $this->auth)->json('data.list.0');
    expect($item['is_available'])->toBeFalse()
        ->and($item['unavailable_reason'])->toBe('商品已下架');
});

test('TC-FAV-006 售罄商品在收藏列表标记为不可用', function () {
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);
    Inventory::where('sku_id', $this->sku->id)->update(['stock' => 0]);

    $item = $this->getJson('/api/me/favorites', $this->auth)->json('data.list.0');
    expect($item['is_available'])->toBeFalse()
        ->and($item['unavailable_reason'])->toBe('商品已售罄');
});

test('TC-FAV-007 批量取消收藏', function () {
    $sku2 = createTestSku(stock: 5, price: '20.00');
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);
    $this->postJson("/api/products/{$sku2->product_id}/favorite", [], $this->auth);

    $resp = $this->postJson('/api/me/favorites/batch-remove', [
        'product_ids' => [$this->productId, $sku2->product_id],
    ], $this->auth)->json();

    expect($resp['data']['removed'])->toBe(2)
        ->and(Favorite::where('user_id', $this->userId)->count())->toBe(0);
});

// ---------- 足迹 ----------

test('TC-FAV-008 足迹上报去重且刷新浏览时间', function () {
    $this->postJson("/api/products/{$this->productId}/track", [], $this->auth);
    $first = BrowseHistory::where('user_id', $this->userId)->first();

    // 手动回拨时间，再次上报应更新为最新
    BrowseHistory::where('id', $first->id)->update(['browsed_at' => now()->subDays(2)]);
    $this->postJson("/api/products/{$this->productId}/track", [], $this->auth);

    expect(BrowseHistory::where('user_id', $this->userId)->count())->toBe(1);
    $fresh = BrowseHistory::where('user_id', $this->userId)->first();
    expect($fresh->browsed_at->greaterThan(now()->subMinute()))->toBeTrue();
});

test('TC-FAV-009 足迹列表按最近浏览倒序并含浏览时间', function () {
    $sku2 = createTestSku();
    $this->postJson("/api/products/{$this->productId}/track", [], $this->auth);
    $this->postJson("/api/products/{$sku2->product_id}/track", [], $this->auth);

    $list = $this->getJson('/api/me/histories', $this->auth)->json('data.list');
    expect($list)->toHaveCount(2)
        ->and($list[0]['id'])->toBe($sku2->product_id) // 最后浏览置顶
        ->and($list[0]['browsed_at'])->not->toBeNull();
});

test('TC-FAV-010 单位清理逻辑：仅保留最近 N 条', function () {
    $service = app(FavoriteService::class);
    for ($i = 0; $i < 5; $i++) {
        $sku = createTestSku();
        $service->track($this->userId, $sku->product_id);
        // 制造时间差，保证顺序确定
        BrowseHistory::where('user_id', $this->userId)->where('product_id', $sku->product_id)
            ->update(['browsed_at' => now()->subMinutes(10 - $i)]);
    }

    $removed = $service->cleanup($this->userId, 3);
    expect($removed)->toBe(2)
        ->and(BrowseHistory::where('user_id', $this->userId)->count())->toBe(3);
});

test('TC-FAV-011 清空足迹', function () {
    $this->postJson("/api/products/{$this->productId}/track", [], $this->auth);
    $this->deleteJson('/api/me/histories', [], $this->auth)->assertOk();

    expect(BrowseHistory::where('user_id', $this->userId)->count())->toBe(0);
});

// ---------- 隔离与鉴权 ----------

test('TC-FAV-012 收藏数据按用户隔离', function () {
    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);

    // 另一个用户
    $cap = app(CaptchaService::class)->generate();
    $otherAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'other'.uniqid(), 'password' => 'Test@1234', 'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'], 'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];

    expect($this->getJson('/api/me/favorites', $otherAuth)->json('data.list'))->toHaveCount(0);
});

test('TC-FAV-013 未登录访问收藏与足迹返回 401', function () {
    $this->getJson('/api/me/favorites')->assertStatus(401);
    $this->postJson("/api/products/{$this->productId}/favorite")->assertStatus(401);
    $this->getJson('/api/me/histories')->assertStatus(401);
});

test('TC-FAV-014 商品详情返回 is_favorited 状态', function () {
    // 未登录默认 false
    expect($this->getJson("/api/products/{$this->productId}")->json('data.is_favorited'))->toBeFalse();

    $this->postJson("/api/products/{$this->productId}/favorite", [], $this->auth);
    expect($this->getJson("/api/products/{$this->productId}", $this->auth)->json('data.is_favorited'))->toBeTrue();
});
