<?php

use App\Models\Category;
use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\Product;
use App\Support\CmsListStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CMS 新闻中心（一期）：用户端 /api/news/* 接口 + 与帮助中心的排除/分流
 *
 * 新闻栏目由迁移 000103 播种（slug=news / news-graphic / news-list），RefreshDatabase 会执行。
 * 全部接口公开（决策 D4 同款），未登录也可访问——新闻必须可被搜索引擎抓取。
 */
beforeEach(function () {
    $this->root = CsFaqCategory::where('slug', 'news')->firstOrFail();
    $this->graphic = CsFaqCategory::where('slug', 'news-graphic')->firstOrFail();
    $this->list = CsFaqCategory::where('slug', 'news-list')->firstOrFail();

    // 新闻文章（图文栏目 2 篇，便于验证上一篇/下一篇；列表栏目 1 篇）
    $this->newsA = CsFaqArticle::create([
        'category_id' => $this->graphic->id, 'title' => '新品上市', 'summary' => '图文摘要',
        'content' => '图文正文', 'cover_image' => '/uploads/cms/news-a.jpg',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'is_hot' => true, 'sort' => 1,
    ]);
    $this->newsA2 = CsFaqArticle::create([
        'category_id' => $this->graphic->id, 'title' => '种草实测', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'is_hot' => false, 'sort' => 3,
    ]);
    $this->newsB = CsFaqArticle::create([
        'category_id' => $this->list->id, 'title' => '行业动态', 'content' => '列表正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'sort' => 2,
    ]);

    // 帮助中心对照（不应出现在任何新闻接口）
    $this->faqCat = CsFaqCategory::create(['name' => '购物指南', 'is_active' => true]);
    $this->faqArt = CsFaqArticle::create([
        'category_id' => $this->faqCat->id, 'title' => '退款说明', 'content' => '帮助正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);
});

it('channels 接口返回根栏目与两个子栏目（带 list_style 与文章数）', function () {
    $res = $this->getJson('/api/news/channels')->assertOk();
    $data = $res->json('data');

    expect($data['root']['name'])->toBe('新闻中心')
        ->and($data['root']['id'])->toBe($this->root->id)
        ->and($data['channels'])->toHaveCount(2);

    $bySlug = collect($data['channels'])->keyBy('slug');
    expect($bySlug['news-graphic']['list_style'])->toBe(CmsListStyle::CARD)
        ->and($bySlug['news-graphic']['published_count'])->toBe(2)
        ->and($bySlug['news-list']['list_style'])->toBe(CmsListStyle::LIST)
        ->and($bySlug['news-list']['published_count'])->toBe(1);
});

it('articles 列表按子栏目过滤且字段裁剪（不含正文，带封面）', function () {
    $res = $this->getJson('/api/news/articles?channel_id='.$this->graphic->id)->assertOk();
    $list = $res->json('data.list');

    expect($list)->toHaveCount(2)
        ->and($list[0]['title'])->toBe('新品上市') // 热门优先
        ->and($list[0])->toHaveKey('cover_image')
        ->and($list[0]['cover_image'])->toBe('/uploads/cms/news-a.jpg')
        ->and($list[0])->not->toHaveKey('content'); // 列表不带正文
});

it('articles 列表不含帮助中心文章', function () {
    $res = $this->getJson('/api/news/articles')->assertOk();
    $titles = collect($res->json('data.list'))->pluck('title')->all();

    expect($titles)->not->toContain('退款说明')
        ->and($titles)->toContain('新品上市')
        ->and($titles)->toContain('行业动态');
});

it('detail 含全文与同栏目上一篇/下一篇', function () {
    $res = $this->getJson('/api/news/articles/'.$this->newsA->id)->assertOk();
    $data = $res->json('data');

    expect($data['article']['content'])->toBe('图文正文') // 详情给全文
        ->and($data['article']['id'])->toBe($this->newsA->id)
        ->and($data['prev'])->toBeNull() // newsA 是同栏目序首
        ->and($data['next']['id'])->toBe($this->newsA2->id)
        ->and($data['next']['title'])->toBe('种草实测');
});

it('未登录也可访问 /api/news（新闻需被搜索引擎抓取）', function () {
    $this->getJson('/api/news/channels')->assertOk();
    $this->getJson('/api/news/articles')->assertOk();
});

it('帮助中心文章列表不含新闻文章', function () {
    $res = $this->getJson('/api/cs/faq/articles')->assertOk();
    $titles = collect($res->json('data.list'))->pluck('title')->all();

    expect($titles)->toContain('退款说明')
        ->and($titles)->not->toContain('新品上市')
        ->and($titles)->not->toContain('行业动态');
});

it('帮助中心栏目树不含新闻中心', function () {
    $res = $this->getJson('/api/cs/faq/categories')->assertOk();
    $names = collect($res->json('data'))->pluck('name')->all();

    expect($names)->not->toContain('新闻中心');
});

it('sitemap 新闻文章走 /news/{id} 且不与帮助中心重复', function () {
    $site = (string) config('cms.site_url');
    $body = $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain('<loc>'.$site.'/news</loc>') // 静态页
        ->and($body)->toContain('<loc>'.$site.'/news/'.$this->newsA->id.'</loc>')
        ->and($body)->not->toContain('<loc>'.$site.'/service-center/faq/'.$this->newsA->id.'</loc>')
        ->and($body)->toContain('<loc>'.$site.'/service-center/faq/'.$this->faqArt->id.'</loc>'); // 帮助中心文常收录
});

// ---------- 后期增强（§7）：slug / 标签 / 热门 / 商品种草 ----------

it('detail 支持 slug 语义化 URL（id 仍可用）', function () {
    $a = CsFaqArticle::create([
        'category_id' => $this->graphic->id, 'title' => 'Slug 新闻', 'slug' => 'slug-news',
        'content' => '正文', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $this->getJson('/api/news/articles/slug-news')->assertOk()
        ->assertJsonPath('data.article.id', $a->id);
    $this->getJson('/api/news/articles/'.$a->id)->assertOk()
        ->assertJsonPath('data.article.slug', 'slug-news');
});

it('sitemap 新闻文章有 slug 时用 slug URL', function () {
    $site = (string) config('cms.site_url');
    CsFaqArticle::create([
        'category_id' => $this->graphic->id, 'title' => 'Slug 入库', 'slug' => 'slug-in-sitemap',
        'content' => '正文', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $body = $this->get('/sitemap.xml')->getContent();
    expect($body)->toContain('<loc>'.$site.'/news/slug-in-sitemap</loc>');
});

it('articles 支持按标签过滤', function () {
    CsFaqArticle::create([
        'category_id' => $this->list->id, 'title' => '带标签', 'content' => 'x',
        'status' => CsFaqArticle::STATUS_PUBLISHED, 'tags' => ['促销'],
    ]);

    $res = $this->getJson('/api/news/articles?tag='.urlencode('促销'))->assertOk();
    $titles = collect($res->json('data.list'))->pluck('title')->all();

    expect($titles)->toContain('带标签')
        ->and($titles)->not->toContain('行业动态');
});

it('tags 接口聚合标签与出现次数', function () {
    CsFaqArticle::create(['category_id' => $this->list->id, 'title' => 'A', 'content' => 'x', 'status' => CsFaqArticle::STATUS_PUBLISHED, 'tags' => ['促销', '新品']]);
    CsFaqArticle::create(['category_id' => $this->list->id, 'title' => 'B', 'content' => 'x', 'status' => CsFaqArticle::STATUS_PUBLISHED, 'tags' => ['促销']]);

    $res = $this->getJson('/api/news/tags')->assertOk();
    $byTag = collect($res->json('data'))->keyBy('tag');

    expect($byTag['促销']['count'])->toBe(2)
        ->and($byTag['新品']['count'])->toBe(1);
});

it('hot 接口按浏览量倒序取前 N', function () {
    $this->newsA->update(['view_count' => 100]);
    $this->newsA2->update(['view_count' => 50]);
    $this->newsB->update(['view_count' => 10]);

    $res = $this->getJson('/api/news/hot?limit=2')->assertOk();
    $titles = collect($res->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['新品上市', '种草实测']);
});

it('detail 带出关联种草商品（id 为 public_id）', function () {
    $category = Category::create(['name' => '种草分类', 'sort' => 1, 'status' => 1]);
    $product = Product::create(['category_id' => $category->id, 'title' => '种草商品', 'main_image' => '/storage/p/x.png', 'price' => 10, 'status' => 1]);
    $this->newsA->products()->sync([$product->id]);

    $res = $this->getJson('/api/news/articles/'.$this->newsA->id)->assertOk();

    expect($res->json('data.products'))->toHaveCount(1)
        ->and($res->json('data.products.0.id'))->toBe($product->public_id)
        ->and($res->json('data.products.0.title'))->toBe('种草商品');
});

it('by-product 反查商品关联的新闻（商品详情页种草位）', function () {
    $category = Category::create(['name' => '商品分类', 'sort' => 1, 'status' => 1]);
    $product = Product::create(['category_id' => $category->id, 'title' => '商品 X', 'price' => 10, 'status' => 1]);
    $this->newsA->products()->sync([$product->id]);

    $res = $this->getJson('/api/news/by-product/'.$product->public_id)->assertOk();
    $titles = collect($res->json('data'))->pluck('title')->all();

    expect($titles)->toContain('新品上市');
});

it('by-product 对未知商品返回空列表而非 404', function () {
    $this->getJson('/api/news/by-product/999999')->assertOk()->assertJsonPath('data', []);
});
