<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
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
