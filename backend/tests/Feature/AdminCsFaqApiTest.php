<?php

use App\Models\Category;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\Product;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-110 后台 FAQ 分类与文章管理 集成测试
 *
 * 鉴权：admin（super_admin，拥有 cs.faq.manage）走成功路径；
 *       operator 未授予该权限，用于校验 403 分支。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $capOp = app(CaptchaService::class)->generate();
    $this->operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator', 'password' => 'Operator@123',
        'captcha_id' => $capOp['captcha_id'], 'captcha_code' => $capOp['debug_code'],
    ])->json('data.token')];

    $this->category = CsFaqCategory::create(['name' => '基础问题', 'sort' => 1, 'is_active' => true]);
});

it('operator 缺权限访问分类列表返回 403', function () {
    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/cs/faq/categories')
        ->assertForbidden()
        ->assertJsonPath('code', 40003);
});

it('创建分类成功且默认启用', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/categories', ['name' => '物流帮助']);
    $res->assertCreated()->assertJsonPath('code', 0);

    $cat = CsFaqCategory::find($res->json('data.id'));
    expect($cat)->not->toBeNull()
        ->and($cat->is_active)->toBeTrue()
        ->and($res->json('data.name'))->toBe('物流帮助');
});

it('分类名重复返回 422', function () {
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/categories', ['name' => '重复分类'])->assertCreated();
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/categories', ['name' => '重复分类'])->assertStatus(422);
});

it('编辑分类成功', function () {
    $res = $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/faq/categories/'.$this->category->id, ['name' => '新名称', 'sort' => 5]);
    $res->assertOk();

    $cat = CsFaqCategory::find($this->category->id);
    expect($cat->name)->toBe('新名称')->and($cat->sort)->toBe(5);
});

it('有已发布文章的分类删除被拒 409', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id, 'title' => '发布文章', 'content' => '正文', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $this->withHeaders($this->adminAuth)->deleteJson('/api/admin/cs/faq/categories/'.$this->category->id)
        ->assertStatus(409)
        ->assertJsonPath('code', 40009);

    expect(CsFaqCategory::find($this->category->id))->not->toBeNull()
        ->and(CsFaqArticle::find($article->id))->not->toBeNull();
});

it('无已发布文章的分类可删除', function () {
    $emptyCat = CsFaqCategory::create(['name' => '空分类', 'sort' => 9, 'is_active' => true]);

    $this->withHeaders($this->adminAuth)->deleteJson('/api/admin/cs/faq/categories/'.$emptyCat->id)->assertOk();
    expect(CsFaqCategory::find($emptyCat->id))->toBeNull();
});

it('批量排序分类', function () {
    $c2 = CsFaqCategory::create(['name' => '二号', 'sort' => 2, 'is_active' => true]);
    $c3 = CsFaqCategory::create(['name' => '三号', 'sort' => 3, 'is_active' => true]);

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/categories/sort', [
        'items' => [
            ['id' => $c3->id, 'sort' => 1],
            ['id' => $this->category->id, 'sort' => 2],
            ['id' => $c2->id, 'sort' => 3],
        ],
    ]);
    $res->assertOk();

    expect(CsFaqCategory::find($c3->id)->sort)->toBe(1)
        ->and(CsFaqCategory::find($this->category->id)->sort)->toBe(2)
        ->and(CsFaqCategory::find($c2->id)->sort)->toBe(3);
});

it('创建文章默认草稿且 published_at 为空', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', [
        'category_id' => $this->category->id, 'title' => '如何退款', 'content_md' => '步骤说明',
    ]);
    $res->assertCreated();

    $article = CsFaqArticle::find($res->json('data.id'));
    expect($article->status)->toBe(CsFaqArticle::STATUS_DRAFT)
        ->and($article->published_at)->toBeNull();
});

it('发布文章后状态为 published 且 published_at 落库', function () {
    $article = CsFaqArticle::create(['category_id' => $this->category->id, 'title' => '草稿', 'content' => 'x', 'status' => CsFaqArticle::STATUS_DRAFT]);

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles/'.$article->id.'/publish');
    $res->assertOk();

    $article->refresh();
    expect($article->status)->toBe(CsFaqArticle::STATUS_PUBLISHED)
        ->and($article->published_at)->not->toBeNull();
});

it('下架文章后状态为 offline 且 published_at 清空', function () {
    $article = CsFaqArticle::create(['category_id' => $this->category->id, 'title' => '已发布', 'content' => 'x', 'status' => CsFaqArticle::STATUS_PUBLISHED, 'published_at' => now()]);

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles/'.$article->id.'/offline');
    $res->assertOk();

    $article->refresh();
    expect($article->status)->toBe(CsFaqArticle::STATUS_OFFLINE)
        ->and($article->published_at)->toBeNull();
});

it('文章列表支持按状态筛选', function () {
    CsFaqArticle::create(['category_id' => $this->category->id, 'title' => '发布文', 'content' => 'x', 'status' => CsFaqArticle::STATUS_PUBLISHED]);
    CsFaqArticle::create(['category_id' => $this->category->id, 'title' => '草稿文', 'content' => 'x', 'status' => CsFaqArticle::STATUS_DRAFT]);

    $published = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/faq/articles?status=published')->json('data.pagination.total');
    $draft = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/faq/articles?status=draft')->json('data.pagination.total');

    expect($published)->toBe(1)->and($draft)->toBe(1);
});

it('删除文章成功', function () {
    $article = CsFaqArticle::create(['category_id' => $this->category->id, 'title' => '待删', 'content' => 'x', 'status' => CsFaqArticle::STATUS_DRAFT]);

    $this->withHeaders($this->adminAuth)->deleteJson('/api/admin/cs/faq/articles/'.$article->id)->assertOk();
    expect(CsFaqArticle::find($article->id))->toBeNull();
});

it('文章预览返回有帮助率', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id, 'title' => '预览', 'content' => 'x',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'helpful_count' => 3, 'unhelpful_count' => 1,
    ]);

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/faq/articles/'.$article->id.'/preview');
    $res->assertOk();

    expect($res->json('data.helpful_rate'))->toBe(0.75)
        ->and($res->json('data.category_name'))->toBe('基础问题');
});

// ---------- 新闻中心后期增强（§7）：slug / SEO / 标签 / 种草商品 ----------

it('创建文章自动生成 slug 并保存 SEO 与标签（标签去重去空）', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', [
        'category_id' => $this->category->id,
        'title' => 'How to refund 退款',
        'content_md' => '正文',
        'seo_title' => '退款指南 SEO',
        'seo_keywords' => '退款,指南',
        'seo_description' => '退款说明描述',
        'tags' => '退款, 指南, 退款 , ', // 含重复与空项
    ]);
    $res->assertCreated();

    $article = CsFaqArticle::find($res->json('data.id'));
    expect($article->slug)->toBe('how-to-refund-退款')
        ->and($article->seo_title)->toBe('退款指南 SEO')
        ->and($article->seo_keywords)->toBe('退款,指南')
        ->and($article->tagList())->toBe(['退款', '指南']);
});

it('显式 slug 冲突返回 422', function () {
    $payload = [
        'category_id' => $this->category->id, 'title' => 'Alpha', 'content_md' => 'x', 'slug' => 'dup-slug',
    ];
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', $payload)->assertCreated();
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', array_merge($payload, ['title' => 'Beta']))
        ->assertStatus(422);
});

it('更新文章传空 slug 不破坏既有 URL，且可清空 SEO 与标签', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id, 'title' => '原标题', 'content' => 'x',
        'slug' => 'keep-me', 'seo_title' => '旧 SEO', 'tags' => ['旧标签'],
    ]);

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/faq/articles/'.$article->id, [
        'slug' => '', 'title' => '新标题', 'seo_title' => '', 'tags' => '',
    ])->assertOk();

    $fresh = $article->fresh();
    expect($fresh->slug)->toBe('keep-me')          // slug 保持稳定
        ->and($fresh->title)->toBe('新标题')
        ->and($fresh->seo_title)->toBeNull()       // 可清空
        ->and($fresh->tagList())->toBe([]);        // 可清空
});

it('文章可关联种草商品并回填 product_ids', function () {
    $category = Category::create(['name' => '种草分类', 'sort' => 1, 'status' => 1]);
    $product = Product::create([
        'category_id' => $category->id, 'title' => '种草商品', 'main_image' => '/storage/p/x.png',
        'price' => 10, 'status' => 1,
    ]);

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', [
        'category_id' => $this->category->id, 'title' => '种草文', 'content_md' => 'x',
        'product_ids' => [$product->id],
    ]);
    $res->assertCreated();
    $id = $res->json('data.id');

    expect(CsFaqArticle::find($id)->products()->pluck('products.id')->all())->toBe([$product->id]);

    // 列表回填 product_ids（编辑器据此预选）
    $row = collect($this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/faq/articles')->json('data.list'))
        ->firstWhere('id', $id);
    expect($row['product_ids'])->toBe([$product->id]);
});

it('只改标题不会清空已关联商品', function () {
    $category = Category::create(['name' => '保留分类', 'sort' => 1, 'status' => 1]);
    $product = Product::create(['category_id' => $category->id, 'title' => '保留商品', 'price' => 10, 'status' => 1]);

    $article = CsFaqArticle::create([
        'category_id' => $this->category->id, 'title' => '关联文', 'content' => 'x',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);
    $article->products()->sync([$product->id]);

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/faq/articles/'.$article->id, [
        'title' => '改个标题',
    ])->assertOk();

    expect($article->fresh()->products()->pluck('products.id')->all())->toBe([$product->id]);
});
