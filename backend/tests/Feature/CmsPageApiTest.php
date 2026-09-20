<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Common\CaptchaService;
use App\Support\CmsBlock;
use App\Support\CmsPageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 内容中心 CMS 接口（CMS-105 后台单页 / CMS-106 前台公开）
 *
 * ⚠️ 全局函数名必须唯一（与其他测试文件重名会导致全量跑 fatal），故用 cmsApi 前缀。
 */
function cmsApiAdminToken(string $username, string $password): string
{
    $cap = app(CaptchaService::class)->generate();

    return test()->postJson('/api/auth/login', [
        'username' => $username,
        'password' => $password,
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token');
}

/** 迁移预置的单页栏目 */
function cmsApiAboutPage(): CsFaqCategory
{
    return CsFaqCategory::where('slug', 'about')->firstOrFail();
}

/** CMS-203：新建一个「自由区块」模板的单页栏目 */
function cmsApiBlocksPage(string $slug = 'brand-story'): CsFaqCategory
{
    return CsFaqCategory::create([
        'name' => '品牌故事',
        'type' => CsFaqCategory::TYPE_PAGE,
        'slug' => $slug,
        'template' => CmsPageTemplate::TEMPLATE_BLOCKS,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $this->adminAuth = ['Authorization' => 'Bearer '.cmsApiAdminToken('admin', 'Admin@123')];
});

// ---------- 后台单页接口（CMS-105） ----------

test('TC-CMS-T30 后台取单页：返回模板 schema 与合并默认值后的字段值', function () {
    $page = cmsApiAboutPage();

    $res = $this->getJson('/api/admin/cs/faq/pages/'.$page->id, $this->adminAuth);

    $res->assertOk()->assertJsonPath('code', 0)
        ->assertJsonPath('data.template.key', 'about')
        ->assertJsonPath('data.category.slug', 'about');

    // values 的键与 schema 完全一致（缺失字段已由后端补默认值）
    $schemaKeys = array_column(CmsPageTemplate::schema('about'), 'key');
    expect(array_keys($res->json('data.values')))->toEqualCanonicalizing($schemaKeys);
});

test('TC-CMS-T31 保存单页字段后，前台公开接口立即返回新内容', function () {
    $page = cmsApiAboutPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'fields' => [
            'intro' => '我们是一家测试公司',
            'milestones' => [['year' => '2020', 'event' => '成立']],
        ],
    ], $this->adminAuth)->assertOk()->assertJsonPath('code', 0);

    $this->getJson('/api/cms/pages/about')
        ->assertOk()
        ->assertJsonPath('data.template', 'about')
        ->assertJsonPath('data.fields.intro', '我们是一家测试公司')
        ->assertJsonPath('data.fields.milestones.0.year', '2020');
});

test('TC-CMS-T32 保存时未知字段被静默丢弃（脏数据进不来）', function () {
    $page = cmsApiAboutPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'fields' => ['intro' => '正文', 'evil' => 'x'],
    ], $this->adminAuth)->assertOk();

    $fields = CsFaqArticle::where('category_id', $page->id)->firstOrFail()->page_fields;

    expect($fields)->toHaveKey('intro')->not->toHaveKey('evil');
});

test('TC-CMS-T33 单页必填字段缺失返回 422', function () {
    $page = cmsApiAboutPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'fields' => ['banner' => '', 'intro' => ''],
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-CMS-T34 对 channel 类型栏目调单页接口返回 422', function () {
    $channel = CsFaqCategory::create([
        'name' => '帮助中心', 'type' => CsFaqCategory::TYPE_CHANNEL,
    ]);

    $this->getJson('/api/admin/cs/faq/pages/'.$channel->id, $this->adminAuth)->assertStatus(422);

    $this->putJson('/api/admin/cs/faq/pages/'.$channel->id, [
        'fields' => ['intro' => 'x'],
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-CMS-T35 后台文章列表不包含单页承载行', function () {
    $this->putJson('/api/admin/cs/faq/pages/'.cmsApiAboutPage()->id, [
        'fields' => ['intro' => 'x'],
    ], $this->adminAuth)->assertOk();

    $channel = CsFaqCategory::create(['name' => '帮助中心', 'type' => CsFaqCategory::TYPE_CHANNEL]);
    CsFaqArticle::create([
        'category_id' => $channel->id, 'title' => '正常文章',
        'content_md' => '内容', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $titles = collect($this->getJson('/api/admin/cs/faq/articles', $this->adminAuth)->json('data.list'))
        ->pluck('title');

    expect($titles)->toContain('正常文章')
        ->and($titles)->not->toContain('关于我们');
});

// ---------- 前台公开接口（CMS-106） ----------

test('TC-CMS-T36 公开接口无需登录：nav / categories / pages 均可访问', function () {
    $this->getJson('/api/cms/nav')->assertOk()->assertJsonPath('code', 0);
    $this->getJson('/api/cms/categories')->assertOk()->assertJsonPath('code', 0);

    $this->getJson('/api/cms/pages/about')
        ->assertOk()
        ->assertJsonPath('data.slug', 'about')
        ->assertJsonPath('data.name', '关于我们');
});

test('TC-CMS-T37 单页 slug 不存在返回 404', function () {
    $this->getJson('/api/cms/pages/not-exist')->assertStatus(404);
});

test('TC-CMS-T38 已下线的单页不可访问', function () {
    $page = cmsApiAboutPage();
    $page->update(['is_active' => false]);

    $this->getJson('/api/cms/pages/about')->assertStatus(404);
});

test('TC-CMS-T39 nav 只返回标记为「显示在导航」的根栏目', function () {
    CsFaqCategory::create(['name' => '不展示栏目', 'show_in_nav' => false, 'type' => CsFaqCategory::TYPE_CHANNEL]);
    CsFaqCategory::create(['name' => '展示栏目', 'show_in_nav' => true, 'type' => CsFaqCategory::TYPE_CHANNEL]);

    $names = array_column($this->getJson('/api/cms/nav')->json('data'), 'name');

    // 迁移预置的两个单页都标记为显示在导航
    expect($names)->toContain('关于我们', '联系我们', '展示栏目')
        ->and($names)->not->toContain('不展示栏目');
});

test('TC-CMS-T40 帮助中心读接口公开，反馈接口仍需登录', function () {
    $this->getJson('/api/cs/faq/categories')->assertOk();
    $this->getJson('/api/cs/faq/articles')->assertOk();

    $article = CsFaqArticle::create([
        'category_id' => CsFaqCategory::create(['name' => '帮助中心', 'type' => CsFaqCategory::TYPE_CHANNEL])->id,
        'title' => 'T', 'content_md' => 'C', 'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $this->postJson('/api/cs/faq/articles/'.$article->id.'/feedback', ['helpful' => true])
        ->assertStatus(401);
});

test('TC-CMS-T41 公开单页接口把 markdown 字段渲染为净化后的 HTML', function () {
    $this->putJson('/api/admin/cs/faq/pages/'.cmsApiAboutPage()->id, [
        'fields' => ['intro' => "## 我们是谁\n\n<script>alert(1)</script>\n\n- 一\n- 二"],
    ], $this->adminAuth)->assertOk();

    $html = $this->getJson('/api/cms/pages/about')->json('data.html.intro');

    // markdown 已渲染为 HTML 结构
    expect($html)->toContain('<h2')->toContain('<ul>')->toContain('<li>一</li>')
        // 原始 script 标签被 HtmlSanitizer 剥掉
        ->and($html)->not->toContain('<script');
});

// ---------- 单页 SEO（CMS-202） ----------

test('TC-CMS-T42 后台可保存并清空 SEO 三列', function () {
    $page = cmsApiAboutPage();

    $this->putJson('/api/admin/cs/faq/categories/'.$page->id, [
        'seo_title' => '关于我们 | 品质电商',
        'seo_keywords' => '电商,正品',
        'seo_description' => '了解我们的团队与理念',
    ], $this->adminAuth)->assertOk();

    expect(cmsApiAboutPage()->only(['seo_title', 'seo_keywords', 'seo_description']))
        ->toBe([
            'seo_title' => '关于我们 | 品质电商',
            'seo_keywords' => '电商,正品',
            'seo_description' => '了解我们的团队与理念',
        ]);

    // ⚠️ 清空必须能过：config_value 的教训 —— 空串被 ConvertEmptyStringsToNull 转 null，
    // 若用 required 就会永远 422
    $this->putJson('/api/admin/cs/faq/categories/'.$page->id, [
        'seo_title' => '',
        'seo_keywords' => '',
        'seo_description' => '',
    ], $this->adminAuth)->assertOk();

    expect(cmsApiAboutPage()->seo_title)->toBeNull();
});

test('TC-CMS-T43 公开单页接口下发 SEO，未填写时按内容回落', function () {
    $page = cmsApiAboutPage();

    // 未填 SEO → title 回落栏目名，description 回落首个 markdown 字段正文
    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'fields' => ['intro' => '我们是一家专注品质的电商团队，坚持正品与极速发货。'],
    ], $this->adminAuth)->assertOk();

    $seo = $this->getJson('/api/cms/pages/about')->json('data.seo');

    expect($seo['title'])->toBe('关于我们')
        ->and($seo['keywords'])->toBe('')
        ->and($seo['description'])->toContain('专注品质');

    // 填写后以填写值为准
    $this->putJson('/api/admin/cs/faq/categories/'.$page->id, [
        'seo_title' => '自定义标题',
        'seo_keywords' => '电商',
        'seo_description' => '自定义描述',
    ], $this->adminAuth)->assertOk();

    $seo = $this->getJson('/api/cms/pages/about')->json('data.seo');
    expect($seo)->toBe([
        'title' => '自定义标题',
        'keywords' => '电商',
        'description' => '自定义描述',
    ]);
});

test('TC-CMS-T44 SEO 描述回落时去掉 markdown 标签且截断', function () {
    $this->putJson('/api/admin/cs/faq/pages/'.cmsApiAboutPage()->id, [
        'fields' => ['intro' => '## 标题'."\n\n".str_repeat('长', 200)],
    ], $this->adminAuth)->assertOk();

    $description = $this->getJson('/api/cms/pages/about')->json('data.seo.description');

    expect($description)->not->toContain('#')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(121); // 120 + 省略号
});

// ---------- 区块化单页（CMS-203） ----------

test('TC-CMS-T45 后台取区块库：五类区块各带自己的字段 schema', function () {
    $options = $this->getJson('/api/admin/cs/faq/page-blocks', $this->adminAuth)
        ->assertOk()
        ->json('data');

    expect(array_column($options, 'key'))->toBe(CmsBlock::blockKeys());

    foreach ($options as $option) {
        expect($option)->toHaveKeys(['key', 'label', 'description', 'fields'])
            ->and($option['fields'])->not->toBeEmpty();
    }
});

test('TC-CMS-T46 保存区块后按数组顺序下发，未知字段被静默过滤', function () {
    $page = cmsApiBlocksPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'blocks' => [
            ['type' => 'hero', 'data' => ['title' => '第一块', 'evil' => 'x']],
            ['type' => 'rich_text', 'data' => ['body' => '第二块正文']],
        ],
    ], $this->adminAuth)->assertOk()->assertJsonPath('code', 0);

    $blocks = $this->getJson('/api/cms/pages/brand-story')->json('data.blocks');

    // 顺序即渲染顺序
    expect(array_column($blocks, 'type'))->toBe(['hero', 'rich_text'])
        // hero 只保留 schema 内的键，且非必填项已补默认值
        ->and($blocks[0]['data'])->toHaveKey('title')->not->toHaveKey('evil')
        ->and($blocks[0]['data'])->toHaveKeys(['subtitle', 'image', 'button_text', 'button_link'])
        // text_image 的 select 默认值同样生效
        ->and($blocks[1]['html']['body'])->toContain('第二块正文');
});

test('TC-CMS-T47 区块类型非法或数量超上限返回 422', function () {
    $page = cmsApiBlocksPage();
    $url = '/api/admin/cs/faq/pages/'.$page->id;

    // 未知区块类型：结构问题，必须报错而不是静默吃掉（否则客户端与真源不同步却无人知晓）
    $this->putJson($url, [
        'blocks' => [['type' => 'not-exist', 'data' => []]],
    ], $this->adminAuth)->assertStatus(422);

    // 必填字段缺失同样 422
    $this->putJson($url, [
        'blocks' => [['type' => 'hero', 'data' => ['title' => '']]],
    ], $this->adminAuth)->assertStatus(422);

    // 数量超上限
    $this->putJson($url, [
        'blocks' => array_fill(0, CmsBlock::MAX_BLOCKS + 1, ['type' => 'rich_text', 'data' => ['body' => 'x']]),
    ], $this->adminAuth)->assertStatus(422);
});

test('TC-CMS-T48 faq_embed 区块随响应带出栏目下已发布文章，草稿不出现', function () {
    $channel = CsFaqCategory::create(['name' => '售后', 'type' => CsFaqCategory::TYPE_CHANNEL]);
    CsFaqArticle::create([
        'category_id' => $channel->id, 'title' => '已发布文章',
        'content_md' => 'C', 'status' => CsFaqArticle::STATUS_PUBLISHED, 'is_hot' => true,
    ]);
    CsFaqArticle::create([
        'category_id' => $channel->id, 'title' => '草稿文章',
        'content_md' => 'C', 'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    $page = cmsApiBlocksPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'blocks' => [
            ['type' => 'faq_embed', 'data' => ['title' => '售后问题', 'category_id' => (string) $channel->id, 'limit' => '5']],
        ],
    ], $this->adminAuth)->assertOk();

    $items = $this->getJson('/api/cms/pages/brand-story')->json('data.blocks.0.items');

    expect(array_column($items, 'title'))->toBe(['已发布文章'])
        // 只带出渲染所需的三列，不泄露正文
        ->and($items[0])->toHaveKeys(['id', 'title', 'summary']);
});

test('TC-CMS-T49 faq_embed 选中的栏目不存在时 items 为空（前台整块不渲染）', function () {
    $page = cmsApiBlocksPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'blocks' => [['type' => 'faq_embed', 'data' => ['category_id' => '999999', 'limit' => '3']]],
    ], $this->adminAuth)->assertOk();

    expect($this->getJson('/api/cms/pages/brand-story')->json('data.blocks.0.items'))->toBe([]);
});

test('TC-CMS-T50 区块内 markdown 渲染为净化 HTML，并参与 SEO 描述回落', function () {
    $page = cmsApiBlocksPage();

    $this->putJson('/api/admin/cs/faq/pages/'.$page->id, [
        'blocks' => [
            ['type' => 'rich_text', 'data' => ['body' => "## 小标题\n\n<script>alert(1)</script>\n\n品牌故事正文段落"]],
        ],
    ], $this->adminAuth)->assertOk();

    $data = $this->getJson('/api/cms/pages/brand-story')->json('data');

    expect($data['blocks'][0]['html']['body'])->toContain('<h2')
        ->and($data['blocks'][0]['html']['body'])->not->toContain('<script')
        // 区块模板没有固定字段，SEO 描述用区块正文回落
        ->and($data['seo']['description'])->toContain('品牌故事正文段落')
        ->and($data['seo']['description'])->not->toContain('#');
});

test('TC-CMS-T51 固定模板与区块模板互不串味（about 不受 blocks 影响）', function () {
    // 给固定模板单页塞一份 blocks：应当被忽略，仍需按 fields 保存
    $this->putJson('/api/admin/cs/faq/pages/'.cmsApiAboutPage()->id, [
        'fields' => ['intro' => '固定模板正文'],
        'blocks' => [['type' => 'hero', 'data' => ['title' => '不该出现']]],
    ], $this->adminAuth)->assertOk();

    $data = $this->getJson('/api/cms/pages/about')->json('data');

    expect($data['blocks'])->toBe([])
        ->and($data['fields']['intro'])->toBe('固定模板正文');
});
