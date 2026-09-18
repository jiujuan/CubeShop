<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-104：用户端 FAQ 接口集成测试
 *
 * 买家端 sanctum 为无状态守卫，必须携带真实登录 token（actingAs 无效），
 * 故在 beforeEach 通过注册接口拿 token。
 */
beforeEach(function () {
    $this->cat = CsFaqCategory::create(['name' => '购物指南', 'sort' => 1, 'is_active' => true]);
    $this->cat2 = CsFaqCategory::create(['name' => '售后政策', 'sort' => 2, 'is_active' => true]);

    $this->publishedA = CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '如何申请退款', 'summary' => '退款说明', 'content' => '请进入订单页申请退款',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'is_hot' => false, 'sort' => 1,
    ]);
    $this->publishedB = CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '优惠券使用', 'summary' => '券说明', 'content' => '结算时勾选优惠券',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'is_hot' => true, 'sort' => 2,
    ]);
    CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '草稿不可见', 'content' => '草稿正文', 'status' => CsFaqArticle::STATUS_DRAFT,
    ]);
    CsFaqArticle::create([
        'category_id' => $this->cat->id, 'title' => '下架不可见', 'content' => '下架正文', 'status' => CsFaqArticle::STATUS_OFFLINE,
    ]);

    // 注册买家拿真实 token（sanctum 无状态守卫必须带 token）
    $cap = app(CaptchaService::class)->generate();
    $token = $this->postJson('/api/auth/register', [
        'username' => 'csfaq'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');

    $this->auth = ['Authorization' => 'Bearer '.$token];
});

it('分类列表只含激活分类且计数正确', function () {
    CsFaqCategory::create(['name' => '隐藏', 'is_active' => false]);

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/faq/categories');

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(2); // 两个激活
    $byName = collect($res->json('data'))->keyBy('name');
    expect($byName['购物指南']['published_count'])->toBe(2);
});

it('文章列表按分类筛选', function () {
    CsFaqArticle::create([
        'category_id' => $this->cat2->id, 'title' => '售后规则', 'content' => '售后规则正文', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/faq/articles?category_id='.$this->cat->id);

    $res->assertOk();
    expect($res->json('data.list'))->toHaveCount(2)
        ->and($res->json('data.list.0.title'))->toBe('优惠券使用'); // 热门优先
});

it('关键词搜索命中标题、摘要、正文', function () {
    $this->withHeaders($this->auth);
    expect($this->getJson('/api/cs/faq/articles?keyword='.urlencode('退款'))->json('data.pagination.total'))->toBe(1);
    expect($this->getJson('/api/cs/faq/articles?keyword='.urlencode('券说明'))->json('data.pagination.total'))->toBe(1);
    expect($this->getJson('/api/cs/faq/articles?keyword='.urlencode('订单页申请'))->json('data.pagination.total'))->toBe(1);
    expect($this->getJson('/api/cs/faq/articles?keyword='.urlencode('不存在词'))->json('data.pagination.total'))->toBe(0);
});

it('草稿与下架文章不出现，详情 404', function () {
    $draft = CsFaqArticle::create(['category_id' => $this->cat->id, 'title' => '草稿', 'content' => '草稿正文', 'status' => CsFaqArticle::STATUS_DRAFT]);

    $this->withHeaders($this->auth)->getJson('/api/cs/faq/articles')->assertOk();
    expect(collect($this->withHeaders($this->auth)->getJson('/api/cs/faq/articles')->json('data.list'))->pluck('title')->all())
        ->not->toContain('草稿')->not->toContain('下架不可见');

    $this->withHeaders($this->auth)->getJson('/api/cs/faq/articles/'.$draft->id)->assertNotFound();
});

it('详情浏览量自增并推荐同分类文章', function () {
    $before = $this->publishedA->view_count;

    $res = $this->withHeaders($this->auth)->getJson('/api/cs/faq/articles/'.$this->publishedA->id);
    $res->assertOk();

    expect($res->json('data.article.view_count'))->toBe($before + 1)
        ->and($res->json('data.related'))->toHaveCount(1) // 同分类已发布且非自身
        ->and($res->json('data.related.0.title'))->toBe('优惠券使用');
});

it('反馈接口累加 helpful / unhelpful', function () {
    $this->withHeaders($this->auth)->postJson('/api/cs/faq/articles/'.$this->publishedA->id.'/feedback', ['helpful' => true])->assertOk();
    $this->withHeaders($this->auth)->postJson('/api/cs/faq/articles/'.$this->publishedA->id.'/feedback', ['helpful' => false])->assertOk();

    $fresh = $this->publishedA->fresh();
    expect($fresh->helpful_count)->toBe(1)->and($fresh->unhelpful_count)->toBe(1);
});

it('未登录访问返回 401', function () {
    $this->getJson('/api/cs/faq/categories')->assertUnauthorized();
    $this->getJson('/api/cs/faq/articles')->assertUnauthorized();
});

it('非法 category_id 返回 422（已登录场景）', function () {
    // 未登录优先被鉴权拦截返回 401（符合「未登录 401」）
    $this->getJson('/api/cs/faq/articles?category_id=99999')->assertUnauthorized();
    // 已登录后校验兜底：不存在的分类返回 422
    $this->withHeaders($this->auth)->getJson('/api/cs/faq/articles?category_id=99999')->assertStatus(422);
});
