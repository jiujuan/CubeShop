<?php

use App\Models\Category;
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

/** ⚠️ 全局函数名唯一，用 catDel 前缀 */
function catDelCategory(array $overrides = []): Category
{
    return Category::create(array_merge([
        'parent_id' => 0,
        'name' => '测试分类',
        'sort' => 0,
        'status' => 1,
    ], $overrides));
}

test('TC-CATDEL-01 删除无子分类无商品的分类：软删除生效且树中消失', function () {
    $cat = catDelCategory();

    $this->deleteJson('/api/admin/categories/'.$cat->id, [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 0);

    // 软删：主表查不到，但 withTrashed 仍在
    expect(Category::find($cat->id))->toBeNull();
    expect(Category::withTrashed()->find($cat->id))->not->toBeNull();

    $this->getJson('/api/admin/categories', $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonMissing(['name' => '测试分类']);
});

test('TC-CATDEL-02 存在子分类时拒绝删除', function () {
    $parent = catDelCategory();
    catDelCategory(['parent_id' => $parent->id, 'name' => '子分类']);

    $this->deleteJson('/api/admin/categories/'.$parent->id, [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 40000);

    expect(Category::withTrashed()->find($parent->id)->deleted_at)->toBeNull();
});

test('TC-CATDEL-03 分类不存在时返回业务错误', function () {
    $this->deleteJson('/api/admin/categories/999999', [], $this->adminAuth)
        ->assertOk()
        ->assertJsonPath('code', 40004);
});
