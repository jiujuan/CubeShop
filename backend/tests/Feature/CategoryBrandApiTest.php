<?php

use App\Models\Brand;
use App\Models\Category;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 分类可选品牌（category_brands，2026-09-19）
 *
 * 品牌与分类是两个正交维度、互不隶属：一个品牌可挂在多个分类下，
 * 一个分类下可有多个品牌。本表只表达「该分类可选哪些品牌」，用于前台按分类收敛品牌范围。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $cap2 = app(CaptchaService::class)->generate();
    $this->userAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'catbrandbuyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];

    $this->categoryId = createTestCategory();
    $this->brandA = Brand::create(['name' => '苹果'.uniqid(), 'sort' => 0, 'status' => 1]);
    $this->brandB = Brand::create(['name' => '华为'.uniqid(), 'sort' => 0, 'status' => 1]);
});

test('TC-CATBRAND-001 后台读取分类可选品牌，默认空', function () {
    $resp = $this->getJson('/api/admin/categories/'.$this->categoryId.'/brands', $this->adminAuth)->json();

    expect($resp['code'])->toBe(0)
        ->and($resp['data']['category_id'])->toBe($this->categoryId)
        ->and($resp['data']['brands'])->toBe([]);
});

test('TC-CATBRAND-002 覆盖保存分类可选品牌，回读顺序按 sort 降序', function () {
    $put = $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', [
        'brands' => [
            ['brand_id' => $this->brandA->id, 'sort' => 10],
            ['brand_id' => $this->brandB->id, 'sort' => 5],
        ],
    ], $this->adminAuth);
    $put->assertStatus(200);
    expect($put->json('code'))->toBe(0);

    $rows = $this->getJson('/api/admin/categories/'.$this->categoryId.'/brands', $this->adminAuth)
        ->json('data.brands');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['brand_id'])->toBe($this->brandA->id)
        ->and($rows[0]['name'])->toBe($this->brandA->name)
        ->and($rows[1]['brand_id'])->toBe($this->brandB->id);

    // 覆盖语义：只留 B，A 的关联被移除
    $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', [
        'brands' => [['brand_id' => $this->brandB->id]],
    ], $this->adminAuth)->assertStatus(200);

    $rows2 = $this->getJson('/api/admin/categories/'.$this->categoryId.'/brands', $this->adminAuth)
        ->json('data.brands');
    expect($rows2)->toHaveCount(1)->and($rows2[0]['brand_id'])->toBe($this->brandB->id);

    // 清空
    $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', ['brands' => []], $this->adminAuth)
        ->assertStatus(200);
    expect($this->getJson('/api/admin/categories/'.$this->categoryId.'/brands', $this->adminAuth)->json('data.brands'))
        ->toBe([]);
});

test('TC-CATBRAND-003 同一品牌可同时挂在多个分类下（两个维度互不隶属）', function () {
    $otherCategoryId = createTestCategory();

    foreach ([$this->categoryId, $otherCategoryId] as $cid) {
        $this->putJson('/api/admin/categories/'.$cid.'/brands', [
            'brands' => [['brand_id' => $this->brandA->id]],
        ], $this->adminAuth)->assertStatus(200);
    }

    foreach ([$this->categoryId, $otherCategoryId] as $cid) {
        $rows = $this->getJson('/api/admin/categories/'.$cid.'/brands', $this->adminAuth)->json('data.brands');
        expect($rows)->toHaveCount(1)->and($rows[0]['brand_id'])->toBe($this->brandA->id);
    }

    // 一个分类下可有多个品牌
    $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', [
        'brands' => [['brand_id' => $this->brandA->id], ['brand_id' => $this->brandB->id]],
    ], $this->adminAuth)->assertStatus(200);
    expect($this->getJson('/api/admin/categories/'.$this->categoryId.'/brands', $this->adminAuth)->json('data.brands'))
        ->toHaveCount(2);
});

test('TC-CATBRAND-004 无效品牌 ID 与不存在的分类被拒绝', function () {
    $bad = $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', [
        'brands' => [['brand_id' => 99999999]],
    ], $this->adminAuth);
    $bad->assertStatus(400);
    expect($bad->json('code'))->toBe(40000);

    $this->getJson('/api/admin/categories/99999999/brands', $this->adminAuth)->assertStatus(404);
    $this->putJson('/api/admin/categories/99999999/brands', ['brands' => []], $this->adminAuth)->assertStatus(404);
});

test('TC-CATBRAND-005 后台接口无权限时拒绝访问', function () {
    $this->getJson('/api/admin/categories/'.$this->categoryId.'/brands', $this->userAuth)->assertStatus(403);
    $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', ['brands' => []], $this->userAuth)->assertStatus(403);
});

test('TC-CATBRAND-006 前台按分类收敛品牌：未配置返回空、停用品牌不出现', function () {
    $emptyCategoryId = createTestCategory();
    $disabled = Brand::create(['name' => '停用'.uniqid(), 'sort' => 0, 'status' => 0]);

    $this->putJson('/api/admin/categories/'.$this->categoryId.'/brands', [
        'brands' => [
            ['brand_id' => $this->brandA->id, 'sort' => 10],
            ['brand_id' => $this->brandB->id, 'sort' => 5],
            ['brand_id' => $disabled->id, 'sort' => 1],
        ],
    ], $this->adminAuth)->assertStatus(200);

    // 前台用 public_id 访问（P2-11 对外标识）
    $publicId = Category::find($this->categoryId)->public_id;
    $resp = $this->getJson('/api/brands?category_id='.$publicId)->json();

    expect($resp['code'])->toBe(0);
    $names = array_column($resp['data'], 'name');
    expect($names)->toHaveCount(2)
        ->and($names[0])->toBe($this->brandA->name)   // 按分类配置的 sort 降序
        ->and($names[1])->toBe($this->brandB->name);

    // 未配置该分类 → 空
    expect($this->getJson('/api/brands?category_id='.$emptyCategoryId)->json('data'))->toBe([]);

    // 不传分类 → 全量（仍只含启用品牌）
    $all = array_column($this->getJson('/api/brands')->json('data'), 'name');
    expect($all)->toContain($this->brandA->name)
        ->and(collect($all)->filter(fn ($n) => $n === $disabled->name))->toHaveCount(0);
});
