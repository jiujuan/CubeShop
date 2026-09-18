<?php

use App\Models\HomeBanner;
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

// ============ 用户端（公开，无需登录） ============

test('公开接口按位置分组只返回启用的记录并按排序输出', function () {
    HomeBanner::create(['position' => 'banner', 'image' => '/img/b2.jpg', 'title' => '轮播B', 'sort_order' => 2, 'is_enabled' => true]);
    HomeBanner::create(['position' => 'banner', 'image' => '/img/b1.jpg', 'title' => '轮播A', 'sort_order' => 1, 'is_enabled' => true]);
    HomeBanner::create(['position' => 'banner', 'image' => '/img/off.jpg', 'title' => '停用轮播', 'sort_order' => 0, 'is_enabled' => false]);
    HomeBanner::create(['position' => 'promo', 'image' => '/img/p1.jpg', 'title' => '手机数码专场', 'subtitle' => '爆款直降', 'link_url' => '/search?keyword=数码']);
    HomeBanner::create(['position' => 'bottom', 'image' => '/img/c1.jpg', 'title' => '限时特惠']);

    $res = $this->getJson('/api/banners');
    $res->assertOk();
    $banners = $res->json('data.banners');

    // 停用不可见
    expect(collect($banners['banner'])->pluck('title')->all())
        ->toBe(['轮播A', '轮播B']); // sort_order 升序

    // 分组正确
    expect($banners['promo'][0]['title'])->toBe('手机数码专场')
        ->and($banners['promo'][0]['subtitle'])->toBe('爆款直降')
        ->and($banners['promo'][0]['link_url'])->toBe('/search?keyword=数码')
        ->and($banners['bottom'][0]['title'])->toBe('限时特惠');

    // 出参用 public_id
    expect($banners['banner'][0]['id'])->toBe(HomeBanner::where('title', '轮播A')->value('public_id'));
});

// ============ 后台管理（home.manage） ============

test('未登录访问后台广告位列表返回 401', function () {
    $this->getJson('/api/admin/home-banners')->assertUnauthorized();
});

test('管理员可创建各位置广告位', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/home-banners', [
        'position' => 'promo',
        'image' => '/uploads/promo-1.jpg',
        'title' => '家居生活好物',
        'subtitle' => '品质家居 舒适生活',
        'link_url' => '/search?keyword=家居',
        'sort_order' => 1,
    ]);
    $res->assertCreated();
    $id = $res->json('data.id');

    $row = HomeBanner::find($id);
    expect($row->title)->toBe('家居生活好物')
        ->and($row->is_enabled)->toBeTrue()
        ->and($row->public_id)->not->toBeNull();

    // 非法 position 拒绝
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/home-banners', [
        'position' => 'invalid', 'image' => '/x.jpg', 'title' => 'X',
    ])->assertStatus(422);
});

test('后台列表按位置筛选', function () {
    HomeBanner::create(['position' => 'banner', 'image' => '/a.jpg', 'title' => '轮播']);
    HomeBanner::create(['position' => 'promo', 'image' => '/b.jpg', 'title' => '广告']);

    $bannerList = $this->withHeaders($this->adminAuth)->getJson('/api/admin/home-banners?position=banner');
    expect(collect($bannerList->json('data.list'))->pluck('title')->all())->toContain('轮播')->not->toContain('广告');

    $promoList = $this->withHeaders($this->adminAuth)->getJson('/api/admin/home-banners?position=promo');
    expect(collect($promoList->json('data.list'))->pluck('title')->all())->toContain('广告')->not->toContain('轮播');
});

test('编辑、启停、删除全流程', function () {
    $row = HomeBanner::create(['position' => 'bottom', 'image' => '/old.jpg', 'title' => '原标题', 'is_enabled' => true]);

    // 编辑
    $upd = $this->withHeaders($this->adminAuth)->putJson("/api/admin/home-banners/{$row->id}", [
        'title' => '新标题', 'image' => '/new.jpg', 'subtitle' => '新副标题',
    ]);
    $upd->assertOk()->assertJsonPath('data.title', '新标题');
    expect(HomeBanner::find($row->id)->subtitle)->toBe('新副标题');

    // 停用 → 公开接口不可见 → 再启用恢复
    $off = $this->withHeaders($this->adminAuth)->postJson("/api/admin/home-banners/{$row->id}/toggle");
    $off->assertOk()->assertJsonPath('data.is_enabled', false);
    expect($this->getJson('/api/banners')->json('data.banners.bottom'))->toBe([]);

    $on = $this->withHeaders($this->adminAuth)->postJson("/api/admin/home-banners/{$row->id}/toggle");
    $on->assertOk()->assertJsonPath('data.is_enabled', true);
    expect($this->getJson('/api/banners')->json('data.banners.bottom'))->not->toBe([]);

    // 删除
    $this->withHeaders($this->adminAuth)->deleteJson("/api/admin/home-banners/{$row->id}")->assertOk();
    expect(HomeBanner::find($row->id))->toBeNull();
});
