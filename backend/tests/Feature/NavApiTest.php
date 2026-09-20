<?php

use App\Models\Category;
use App\Models\NavItem;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();

    // ⚠️ 迁移 000100 会播种「热销推荐」+ 现有分类，这里清空以隔离用例
    // （播种本身由 tests/Feature/NavSeedTest.php 单独断言）
    NavItem::query()->delete();

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/**
 * ⚠️ 全局函数名必须唯一（与其他测试文件重名会导致全量跑 fatal），故用 navApi 前缀。
 */
function navApiCategory(array $overrides = []): Category
{
    return Category::create(array_merge([
        'parent_id' => 0,
        'name' => '测试分类',
        'sort' => 0,
        'status' => 1,
    ], $overrides));
}

function navApiItem(array $overrides = []): NavItem
{
    return NavItem::create(array_merge([
        'type' => NavItem::TYPE_CUSTOM,
        'title' => '新闻中心',
        'url' => '/news',
        'target' => '_self',
        'sort' => 0,
        'is_active' => true,
    ], $overrides));
}

// ============ 公开接口 ============

test('TC-NAV-001 公开接口无需登录', function () {
    $this->getJson('/api/nav')->assertOk()->assertJsonPath('code', 0);
});

test('TC-NAV-002 按 sort 倒序返回（越大越靠前）', function () {
    navApiItem(['title' => 'C', 'url' => '/c', 'sort' => 10]);
    navApiItem(['title' => 'A', 'url' => '/a', 'sort' => 30]);
    navApiItem(['title' => 'B', 'url' => '/b', 'sort' => 20]);

    $titles = collect($this->getJson('/api/nav')->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['A', 'B', 'C']);
});

test('TC-NAV-003 分类条目：标题与链接由分类派生，改名自动跟随', function () {
    $category = navApiCategory(['name' => '运动户外']);
    $item = navApiItem([
        'type' => NavItem::TYPE_CATEGORY,
        'title' => null,
        'url' => null,
        'category_id' => $category->id,
        'sort' => 10,
    ]);

    $data = $this->getJson('/api/nav')->json('data.0');

    // 后台存的是引用，标题取自分类而非拷贝
    expect($data['title'])->toBe('运动户外')
        ->and($data['url'])->toBe('/category/'.$category->public_id)
        ->and($data['category_public_id'])->toBe($category->public_id);

    $category->update(['name' => '户外运动']);

    expect($this->getJson('/api/nav')->json('data.0.title'))->toBe('户外运动')
        ->and($item->fresh()->title)->toBeNull(); // 确认没把名字缓存进 nav_items
});

test('TC-NAV-004 分类被软删后条目静默跳过（nav_items 条目保留）', function () {
    $category = navApiCategory(['name' => '将被删除']);
    navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id]);
    navApiItem(['title' => '新闻中心', 'sort' => 5]);

    expect($this->getJson('/api/nav')->json('data'))->toHaveCount(2);

    // 分类管理里的删除是软删除
    $category->delete();
    expect(Category::find($category->id))->toBeNull()
        ->and(Category::withTrashed()->find($category->id))->not->toBeNull();

    $data = $this->getJson('/api/nav')->json('data');
    // 失效引用不返回，但条目还在 —— 分类恢复后位置不丢
    expect($data)->toHaveCount(1)
        ->and($data[0]['title'])->toBe('新闻中心')
        ->and(NavItem::where('category_id', $category->id)->exists())->toBeTrue();
});

test('TC-NAV-005 分类停用后条目跳过，恢复启用后自动回来', function () {
    $category = navApiCategory(['name' => '停用分类']);
    navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id, 'sort' => 10]);

    expect($this->getJson('/api/nav')->json('data'))->toHaveCount(1);

    $category->update(['status' => 0]);
    expect($this->getJson('/api/nav')->json('data'))->toHaveCount(0);

    $category->update(['status' => 1]);
    expect($this->getJson('/api/nav')->json('data.0.title'))->toBe('停用分类');
});

test('TC-NAV-006 自定义条目直出 title/url/target（外链可 _blank）', function () {
    navApiItem([
        'title' => '客服中心',
        'url' => '/service-center',
        'target' => '_self',
        'sort' => 20,
    ]);
    navApiItem([
        'title' => '外部资讯',
        'url' => 'https://example.com/news',
        'target' => '_blank',
        'sort' => 10,
    ]);

    $data = $this->getJson('/api/nav')->json('data');

    expect($data[0])->toMatchArray([
        'type' => 'custom', 'title' => '客服中心', 'url' => '/service-center', 'target' => '_self',
    ])->and($data[1])->toMatchArray([
        'type' => 'custom', 'title' => '外部资讯', 'url' => 'https://example.com/news', 'target' => '_blank',
    ]);
});

test('TC-NAV-007 停用的条目不出现在公开接口', function () {
    navApiItem(['title' => '显示', 'sort' => 20]);
    navApiItem(['title' => '隐藏', 'sort' => 10, 'is_active' => false]);

    expect(collect($this->getJson('/api/nav')->json('data'))->pluck('title')->all())->toBe(['显示']);
});

test('TC-NAV-008 空导航返回空数组而非报错', function () {
    expect($this->getJson('/api/nav')->json('data'))->toBe([]);
});

// ============ 后台管理 ============

test('TC-NAV-010 后台列表带分类当前名称', function () {
    $category = navApiCategory(['name' => '数码配件']);
    navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id]);

    $row = $this->withHeaders($this->adminAuth)->getJson('/api/admin/nav-items')->json('data.0');

    expect($row['category_name'])->toBe('数码配件')
        ->and($row['category_missing'])->toBeFalse()
        ->and($row['type_label'])->toBe('商品分类');
});

test('TC-NAV-011 后台列表标记失效引用（分类已软删）', function () {
    $category = navApiCategory();
    navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id]);
    $category->delete();

    $row = $this->withHeaders($this->adminAuth)->getJson('/api/admin/nav-items')->json('data.0');

    expect($row['category_missing'])->toBeTrue()
        ->and($row['category_name'])->toBeNull();
});

test('TC-NAV-012 新建分类引用条目', function () {
    $category = navApiCategory(['name' => '美妆个护']);

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'category',
        'category_id' => $category->id,
        'sort' => 50,
    ])->assertOk();

    expect(NavItem::where('category_id', $category->id)->where('type', 'category')->exists())->toBeTrue();
});

test('TC-NAV-013 同一分类重复登记被拒', function () {
    $category = navApiCategory();
    navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id]);

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'category',
        'category_id' => $category->id,
    ])->assertOk()->assertJsonPath('code', 40000);
});

test('TC-NAV-014 引用软删分类被拒（exists 规则必须排除软删）', function () {
    $category = navApiCategory();
    $category->delete();

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'category',
        'category_id' => $category->id,
    ])->assertStatus(422);
});

test('TC-NAV-015 自定义条目缺 title/url 报 422', function () {
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'custom',
        'title' => '',
        'url' => 'https://example.com',
    ])->assertStatus(422);

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'custom',
        'title' => '新闻中心',
        'url' => '',
    ])->assertStatus(422);
});

test('TC-NAV-016 新建条目未指定 sort 时排到末尾', function () {
    navApiItem(['title' => '已有', 'sort' => 10]);

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'custom', 'title' => '新项', 'url' => '/new',
    ])->assertOk();

    $titles = collect($this->getJson('/api/nav')->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['已有', '新项']);
});

test('TC-NAV-017 从分类改成自定义链接会清空 category_id（释放唯一索引）', function () {
    $category = navApiCategory();
    $item = navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id]);

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/nav-items/'.$item->id, [
        'type' => 'custom',
        'title' => '新闻中心',
        'url' => '/news',
    ])->assertOk();

    $fresh = $item->fresh();
    expect($fresh->type)->toBe('custom')
        ->and($fresh->category_id)->toBeNull()
        ->and($fresh->url)->toBe('/news');

    // 释放后可以重新登记该分类
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/nav-items', [
        'type' => 'category', 'category_id' => $category->id,
    ])->assertOk()->assertJsonPath('code', 0);
});

test('TC-NAV-018 局部更新不会洗掉未提交的字段', function () {
    $item = navApiItem(['title' => '新闻中心', 'url' => '/news', 'sort' => 77]);

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/nav-items/'.$item->id, [
        'is_active' => false,
    ])->assertOk();

    $fresh = $item->fresh();
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->sort)->toBe(77) // ⚠️ 不能被回落成 0
        ->and($fresh->title)->toBe('新闻中心');
});

test('TC-NAV-019 删除条目只删编排，不动分类', function () {
    $category = navApiCategory();
    $item = navApiItem(['type' => NavItem::TYPE_CATEGORY, 'title' => null, 'url' => null, 'category_id' => $category->id]);

    $this->withHeaders($this->adminAuth)->deleteJson('/api/admin/nav-items/'.$item->id)->assertOk();

    expect(NavItem::find($item->id))->toBeNull()
        ->and(Category::find($category->id))->not->toBeNull();
});

test('TC-NAV-020 未登录访问后台接口 401', function () {
    $this->getJson('/api/admin/nav-items')->assertStatus(401);
});

test('TC-NAV-021 无 nav.manage 权限返回 403', function () {
    // ⚠️ operator 在迁移 000101 中被授予了 nav.manage，故这里用一个
    // **不属于任何角色**的账号验证中间件接线（登录本身不需要权限）
    \App\Models\SysUser::create([
        'username' => 'navnoperm',
        'password' => \Illuminate\Support\Facades\Hash::make('Pass@1234'),
        'nickname' => '无权限',
        'status' => 1,
    ]);

    $cap = app(CaptchaService::class)->generate();
    $token = $this->postJson('/api/auth/login', [
        'username' => 'navnoperm',
        'password' => 'Pass@1234',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/admin/nav-items')->assertStatus(403);
});

test('TC-NAV-022 operator 持有 nav.manage，可管理导航', function () {
    $cap = app(CaptchaService::class)->generate();
    $token = $this->postJson('/api/auth/login', [
        'username' => 'operator',
        'password' => 'Operator@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/admin/nav-items', [
            'type' => 'custom', 'title' => '客服中心', 'url' => '/service-center',
        ])->assertOk();
});

// ============ 与分类管理的联动（决策 D3） ============

test('TC-NAV-030 新建一级分类自动追加到导航末尾', function () {
    navApiItem(['title' => '已有', 'sort' => 10]);

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/categories', [
        'parent_id' => 0,
        'name' => '新一级分类',
        'sort' => 999, // 分类内部排最前，但导航里按 D3 追加到末尾
    ])->assertOk();

    $titles = collect($this->getJson('/api/nav')->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['已有', '新一级分类']);
});

test('TC-NAV-031 新建二级分类不进导航', function () {
    $root = navApiCategory(['name' => '父分类']);

    $before = NavItem::count();

    $this->withHeaders($this->adminAuth)->postJson('/api/admin/categories', [
        'parent_id' => $root->id,
        'name' => '子分类',
    ])->assertOk();

    expect(NavItem::count())->toBe($before);
});
