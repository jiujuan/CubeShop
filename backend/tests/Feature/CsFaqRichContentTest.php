<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Services\Common\CaptchaService;
use App\Support\HtmlSanitizer;
use App\Support\MarkdownRenderer;
use Database\Seeders\CsFaqCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-112 正文链路回归：markdown 源（content_md）→ 渲染 → 净化 → HTML 产物（content）
 *
 * 背景：正文编辑器换成 md-editor-v3（markdown）后，正文变成「两列一对」：
 * `content_md` 是唯一可编辑的源，`content` 是渲染并净化后的 HTML 产物。
 * 本文件锁定这条链路的四个端点：
 *   ① 接口只认 content_md，落库的 content 由服务端渲染派生（不可由客户端直接给 HTML 绕过）；
 *   ② markdown 里内嵌的原始 HTML 一律转义成文本，不产生可执行标签；
 *   ③ 旧路径（直接写 content）仍走白名单净化，供存量清洗迁移使用；
 *   ④ 存量迁移 000044 能把 HTML 正文转成 markdown 回填，且幂等。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $this->category = CsFaqCategory::create(['name' => '基础问题', 'sort' => 1, 'is_active' => true]);
});

/**
 * 收集 HTML 里的危险节点（可执行标签 / on* 事件属性）。
 *
 * 刻意走 DOM 而不是字符串包含判断：转义后的文本（`&lt;img onerror=...&gt;`）里
 * 也会出现 `onerror=` 字面量，字符串匹配无法区分「属性」与「已转义的文本」。
 *
 * @return array<int, string>
 */
function faqDangerNodes(string $html): array
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>');
    libxml_clear_errors();

    $bad = [];

    foreach ($doc->getElementsByTagName('*') as $el) {
        if (! $el instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($el->nodeName);

        if (in_array($tag, ['script', 'iframe', 'object', 'embed', 'form', 'svg', 'input'], true)) {
            $bad[] = '<'.$tag.'>';

            continue;
        }

        foreach ($el->attributes as $attr) {
            if (str_starts_with(strtolower($attr->nodeName), 'on')) {
                $bad[] = $tag.'@'.$attr->nodeName;
            }
        }
    }

    return $bad;
}

it('后台新建文章：content_md 是源，落库的 content 由服务端渲染派生', function () {
    $markdown = "## 支付方式\n\n支持**余额**与线下转账。\n\n"
        ."[危险链接](javascript:alert(3))\n\n"
        ."<script>alert(1)</script>\n\n"
        ."![图](/storage/uploads/a.png)\n\n"
        ."| 方式 | 时效 |\n|---|---|\n| 余额 | 即时 |";

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', [
        'category_id' => $this->category->id,
        'title' => '富文本文章',
        'content_md' => $markdown,
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);
    $res->assertCreated();

    $article = CsFaqArticle::findOrFail($res->json('data.id'));
    $content = (string) $article->getRawOriginal('content');

    // 源按原样保存（编辑器 round-trip 不失真）
    expect($article->getRawOriginal('content_md'))->toBe($markdown);

    // 渲染产物：标题（带中文锚点 id）/ 段落 / 加粗 / 图片 / 表格
    expect($content)->toContain('支付方式')
        ->toContain('支持<strong>余额</strong>与线下转账。')
        ->toContain('<img src="/storage/uploads/a.png" alt="图">')
        ->toContain('<table>')
        ->toContain('id="content-');

    // 危险内容不产生可执行结构
    expect($content)->not->toContain('<script')
        ->not->toContain('javascript:')
        ->and(faqDangerNodes($content))->toBe([]);

    // 内嵌原始 HTML 是「被转义成文本」而不是被悄悄删掉（作者能看到自己写了什么）
    expect($content)->toContain('&lt;script&gt;');

    // 落库内容已经是净化后的（重复净化不再变化）
    expect(HtmlSanitizer::cleanHtml($content))->toBe($content);
});

it('后台编辑文章：content_md 更新后 content 同步重算（不能靠先建后改绕过）', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id,
        'title' => '原文章',
        'content_md' => '原正文',
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    $this->withHeaders($this->adminAuth)->putJson('/api/admin/cs/faq/articles/'.$article->id, [
        'content_md' => "新正文\n\n<iframe src=\"//evil.com\"></iframe>\n\n<p onclick=\"x()\">点我</p>",
    ])->assertOk();

    $fresh = $article->fresh();
    $content = (string) $fresh->getRawOriginal('content');

    expect($content)->toContain('新正文')
        ->not->toContain('原正文')
        ->not->toContain('<iframe')
        // 内嵌 HTML 转义成文本，不会产出事件属性
        ->and(faqDangerNodes($content))->toBe([]);
});

it('接口不再接受 content 入参：只给 HTML 缺 content_md 会被 422 拦下', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/faq/articles', [
        'category_id' => $this->category->id,
        'title' => '想直接塞 HTML',
        'content' => '<p>绕过 markdown 的写法</p>',
    ])->assertStatus(422);

    // 校验错误在 data.errors（项目统一信封），不是 Laravel 默认的顶层 errors
    expect($res->json('data.errors'))->toHaveKey('content_md');
});

it('模型写入语义：同时给 content_md 与 content 时以 content_md 为准', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id,
        'title' => '优先级',
        'content_md' => '## 小节',
        // 刻意放在后面，验证「谁后写谁赢」被消除
        'content' => '<p>这段应该被忽略</p>',
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    expect($article->getRawOriginal('content'))->toContain('<h2')
        ->not->toContain('这段应该被忽略');
});

it('旧路径：只写 content 时仍按 HTML 净化（供存量清洗迁移使用）', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id,
        'title' => '旧路径',
        'content' => '<p>旧正文</p><script>alert(1)</script>',
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    expect($article->getRawOriginal('content'))->toBe('<p>旧正文</p>')
        ->and($article->content_md)->toBeNull()
        ->and(faqDangerNodes((string) $article->getRawOriginal('content')))->toBe([]);
});

it('用户端详情接口返回未转义的 HTML 片段（前端可直接渲染成元素）', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id,
        'title' => '退换货政策',
        'content_md' => "签收后 7 天内可申请。\n\n- 商品完好\n- 配件齐全",
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $cap = app(CaptchaService::class)->generate();
    $token = $this->postJson('/api/auth/register', [
        'username' => 'faqrich'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');

    $res = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/cs/faq/articles/'.$article->id)
        ->assertOk();

    $content = $res->json('data.article.content');

    expect($content)->toContain('<p>签收后 7 天内可申请。</p>')
        ->toContain('<ul>')
        ->toContain('<li>商品完好</li>')
        // 未被 HTML 转义（否则前端会显示成字面量标签，即 CS-112 缺陷 #1 的表现）
        ->not->toContain('&lt;p&gt;');
});

it('后台预览接口同时返回 markdown 源与渲染产物', function () {
    $article = CsFaqArticle::create([
        'category_id' => $this->category->id,
        'title' => '预览文章',
        'content_md' => "预览正文\n\n<script>alert(9)</script>",
        'status' => CsFaqArticle::STATUS_PUBLISHED,
    ]);

    $res = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/cs/faq/articles/'.$article->id.'/preview')
        ->assertOk();

    // 编辑器回显读 content_md，预览渲染读 content
    expect($res->json('data.content_md'))->toContain('预览正文')
        ->and($res->json('data.content'))->toContain('<p>预览正文</p>')
        ->not->toContain('<script');
});

it('文章列表返回 content_md（编辑弹窗回显需要）', function () {
    CsFaqArticle::create([
        'category_id' => $this->category->id, 'title' => '列表回显',
        'content_md' => '正文内容', 'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    $res = $this->withHeaders($this->adminAuth)
        ->getJson('/api/admin/cs/faq/articles')
        ->assertOk();

    expect($res->json('data.list.0.content_md'))->toBe('正文内容');
});

it('种子示例文章：写入 markdown 源，并渲染出 HTML 产物', function () {
    $this->seed(CsFaqCategorySeeder::class);

    // 取一篇「多段纯文本」的示例（购物指南那篇是编号列表，渲染成 <ol>）
    $article = CsFaqArticle::query()->where('title', '下单后多久发货？如何查看物流？')->firstOrFail();

    // 源是 markdown（不再是 HTML 标签），产物是渲染后的 HTML
    expect($article->getRawOriginal('content_md'))->not->toContain('<p>')
        ->and($article->getRawOriginal('content_md'))->toContain('**48 小时内**')
        ->and($article->getRawOriginal('content'))->toContain('<p>')
        ->and($article->getRawOriginal('content'))->toContain('<strong>48 小时内</strong>');
});

it('存量迁移 000044：HTML 正文转 markdown 回填 content_md，并重算 content', function () {
    // 造一条「迁移上线前」的行：只有 HTML，没有 markdown 源
    $legacy = CsFaqArticle::create([
        'category_id' => $this->category->id,
        'title' => '历史文章',
        'content' => '<h2>小节标题</h2><p>第一段。</p><p>第二段。</p><ul><li>要点</li></ul>',
        'status' => CsFaqArticle::STATUS_DRAFT,
    ]);

    expect($legacy->content_md)->toBeNull();

    $migration = require database_path('migrations/2026_09_17_000044_convert_cs_faq_article_content_to_markdown.php');
    $migration->up();

    $migrated = $legacy->fresh();

    // markdown 源已回填
    expect($migrated->content_md)->not->toBeNull()
        ->and($migrated->content_md)->toContain('小节标题')
        ->and($migrated->content_md)->toContain('第一段。');

    // content 被重算，且等于「渲染(content_md) 后净化」——两列保持一致
    $expected = HtmlSanitizer::cleanHtml(MarkdownRenderer::toHtml($migrated->content_md));

    expect($migrated->getRawOriginal('content'))->toBe($expected)
        ->and($expected)->toContain('第二段。');

    // 幂等：再跑一次不改变结果（只处理 content_md 为空的行）
    $migration->up();

    expect($migrated->fresh()->getRawOriginal('content'))->toBe($expected)
        ->and($migrated->fresh()->content_md)->toBe($migrated->content_md);
});
