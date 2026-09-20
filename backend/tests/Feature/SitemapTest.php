<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CMS-202：站点地图与爬虫协议
 *
 * ⚠️ sitemap 由后端产出但列的是**前台** URL，所以断言一律以 config('cms.site_url') 为基准，
 * 不能写死 APP_URL —— 那正是这个配置要解决的问题。
 */
beforeEach(function () {
    $this->site = (string) config('cms.site_url');

    $this->channel = CsFaqCategory::create(['name' => '购物指南', 'is_active' => true]);
});

it('sitemap 是合法 XML 且内容类型正确', function () {
    $res = $this->get('/sitemap.xml');

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/xml');

    // 能被 XML 解析器读出来才算合法（拼字符串最容易在这里翻车）
    $xml = simplexml_load_string($res->getContent());
    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('urlset');
});

it('sitemap 收录静态核心页', function () {
    $body = $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain('<loc>'.$this->site.'/</loc>')
        ->and($body)->toContain('<loc>'.$this->site.'/service-center/faq</loc>')
        ->and($body)->toContain('<loc>'.$this->site.'/announcements</loc>');
});

it('sitemap 只收录已发布文章，草稿与下架不出现', function () {
    $published = CsFaqArticle::create([
        'category_id' => $this->channel->id, 'title' => '已发布', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);
    $draft = CsFaqArticle::create([
        'category_id' => $this->channel->id, 'title' => '草稿', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);
    $offline = CsFaqArticle::create([
        'category_id' => $this->channel->id, 'title' => '下架', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_OFFLINE,
    ]);

    $body = $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain($this->site.'/service-center/faq/'.$published->id)
        ->and($body)->not->toContain($this->site.'/service-center/faq/'.$draft->id)
        ->and($body)->not->toContain($this->site.'/service-center/faq/'.$offline->id);
});

it('sitemap 收录已启用单页，停用的不出现', function () {
    $body = $this->get('/sitemap.xml')->getContent();

    // 000095 播种的 about 默认启用
    expect($body)->toContain($this->site.'/p/about');

    CsFaqCategory::where('slug', 'contact')->update(['is_active' => false]);

    $bodyAfter = $this->get('/sitemap.xml')->getContent();
    expect($bodyAfter)->not->toContain($this->site.'/p/contact');
});

it('sitemap 不含单页承载的文章行（内容寄生于栏目，不作为文章收录）', function () {
    $page = CsFaqCategory::where('slug', 'about')->firstOrFail();
    $article = CsFaqArticle::create([
        'category_id' => $page->id, 'title' => '关于我们', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    expect($this->get('/sitemap.xml')->getContent())
        ->not->toContain($this->site.'/service-center/faq/'.$article->id);
});

it('sitemap 带 lastmod 且URL指向前台站点而非 API 域名', function () {
    CsFaqArticle::create([
        'category_id' => $this->channel->id, 'title' => '已发布', 'content' => '正文',
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $body = $this->get('/sitemap.xml')->getContent();

    expect($body)->toContain('<lastmod>')
        ->and($body)->not->toContain('/api/');
});

it('robots.txt 指向本机 sitemap 地址', function () {
    $res = $this->get('/robots.txt');

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('text/plain')
        ->and($res->getContent())->toContain('User-agent: *')
        ->and($res->getContent())->toContain('Sitemap: '.$this->site.'/sitemap.xml');
});
