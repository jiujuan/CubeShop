<?php

use App\Models\Category;
use App\Models\Product;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 建一个商品（默认上架、默认不推荐） */
function homeProduct(array $overrides = []): Product
{
    $category = Category::create([
        'parent_id' => 0, 'name' => '分类'.uniqid(), 'sort' => 0, 'status' => 1,
    ]);

    return Product::create([
        'category_id' => $category->id,
        'title' => '商品'.uniqid(),
        'price' => '10.00',
        'status' => 1,
        ...$overrides,
    ]);
}

/** 直接改上架时间（created_at 不在 fillable，只能属性赋值后保存） */
function withCreatedAt(Product $product, string $at): Product
{
    $product->created_at = $at;
    $product->save();

    return $product;
}

// ============ 公开接口 GET /products/recommended ============

test('TC-HR-01 只返回「上架 + 已勾选首页推荐」的商品，出参用 public_id', function () {
    $on = homeProduct(['title' => '推荐A', 'is_home_recommended' => true]);
    homeProduct(['title' => '未推荐', 'is_home_recommended' => false, 'sort' => 999]);
    homeProduct(['title' => '已下架但推荐', 'is_home_recommended' => true, 'status' => 0, 'sort' => 999]);

    $res = $this->getJson('/api/products/recommended');
    $res->assertOk();

    $list = $res->json('data.list');

    expect(collect($list)->pluck('title')->all())->toBe(['推荐A'])
        ->and($list[0]['id'])->toBe($on->public_id);
});

test('TC-HR-02 排序：sort 倒序优先，同排序按上架时间倒序', function () {
    $a = withCreatedAt(homeProduct(['title' => '排序低但最新', 'is_home_recommended' => true, 'sort' => 1]), now()->toDateTimeString());
    $b = withCreatedAt(homeProduct(['title' => '排序高且较新', 'is_home_recommended' => true, 'sort' => 5]), now()->subDay()->toDateTimeString());
    $c = withCreatedAt(homeProduct(['title' => '排序高但更旧', 'is_home_recommended' => true, 'sort' => 5]), now()->subDays(3)->toDateTimeString());

    $titles = collect($this->getJson('/api/products/recommended')->json('data.list'))->pluck('title')->all();

    expect($titles)->toBe(['排序高且较新', '排序高但更旧', '排序低但最新'])
        ->and($titles)->toContain($b->title)
        ->and($a->title)->toBe('排序低但最新');
});

test('TC-HR-03 limit 生效，默认取 12 条', function () {
    for ($i = 0; $i < 15; $i++) {
        homeProduct(['title' => 'R'.$i, 'is_home_recommended' => true, 'sort' => $i]);
    }

    expect($this->getJson('/api/products/recommended')->json('data.list'))->toHaveCount(12)
        ->and($this->getJson('/api/products/recommended?limit=3')->json('data.list'))->toHaveCount(3);
});

test('TC-HR-04 商品被软删除后不再出现在推荐栏', function () {
    $p = homeProduct(['title' => '待删除推荐', 'is_home_recommended' => true]);
    $p->delete();

    expect($this->getJson('/api/products/recommended')->json('data.list'))->toBe([]);
});

// ============ 后台：商品新增 / 编辑勾选 ============

test('TC-HR-05 后台新建商品可勾选首页推荐，详情回显且前台可见', function () {
    $id = $this->postJson('/api/admin/products', [
        'category_id' => createTestCategory(),
        'title' => '后台推荐商品',
        'status' => 1,
        'is_home_recommended' => true,
        'skus' => [[
            'sku_code' => 'HR-'.uniqid(),
            'specs' => ['规格' => '默认'],
            'price' => '9.90',
            'stock' => 5,
        ]],
    ], $this->adminAuth)->json('data.id');

    expect(Product::find($id)->is_home_recommended)->toBeTrue();

    $detail = $this->getJson("/api/admin/products/{$id}", $this->adminAuth)->json('data');
    expect($detail['is_home_recommended'])->toBeTrue();

    $titles = collect($this->getJson('/api/products/recommended')->json('data.list'))->pluck('title')->all();
    expect($titles)->toContain('后台推荐商品');
});

test('TC-HR-06 后台取消勾选后前台立即不再展示', function () {
    $id = $this->postJson('/api/admin/products', [
        'category_id' => createTestCategory(),
        'title' => '先推荐后取消',
        'status' => 1,
        'is_home_recommended' => true,
        'skus' => [[
            'sku_code' => 'HR-'.uniqid(),
            'specs' => ['规格' => '默认'],
            'price' => '9.90',
            'stock' => 5,
        ]],
    ], $this->adminAuth)->json('data.id');

    expect($this->getJson('/api/products/recommended')->json('data.list'))->toHaveCount(1);

    $this->putJson("/api/admin/products/{$id}", ['is_home_recommended' => false], $this->adminAuth)->assertOk();

    expect(Product::find($id)->is_home_recommended)->toBeFalse()
        ->and($this->getJson('/api/products/recommended')->json('data.list'))->toBe([]);
});

test('TC-HR-07 未勾选时默认 false，历史商品不受影响', function () {
    $id = $this->postJson('/api/admin/products', [
        'category_id' => createTestCategory(),
        'title' => '不推荐商品',
        'status' => 1,
        'skus' => [[
            'sku_code' => 'HR-'.uniqid(),
            'specs' => ['规格' => '默认'],
            'price' => '9.90',
            'stock' => 5,
        ]],
    ], $this->adminAuth)->json('data.id');

    expect(Product::find($id)->is_home_recommended)->toBeFalse()
        ->and($this->getJson('/api/products/recommended')->json('data.list'))->toBe([]);
});

test('TC-HR-08 非法布尔值返回 422', function () {
    $this->postJson('/api/admin/products', [
        'category_id' => createTestCategory(),
        'title' => '非法推荐值',
        'status' => 1,
        'is_home_recommended' => 'not-a-bool',
        'skus' => [[
            'sku_code' => 'HR-'.uniqid(),
            'specs' => ['规格' => '默认'],
            'price' => '9.90',
            'stock' => 5,
        ]],
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-HR-09 后台商品列表带推荐标记，便于运营核对', function () {
    homeProduct(['title' => '列表里推荐的那件', 'is_home_recommended' => true]);

    $list = $this->getJson('/api/admin/products', $this->adminAuth)->json('data.list');

    expect(collect($list)->pluck('is_home_recommended'))->toContain(true);
});
