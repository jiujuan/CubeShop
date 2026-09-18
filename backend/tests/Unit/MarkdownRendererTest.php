<?php

use App\Support\MarkdownRenderer;

/**
 * MarkdownRenderer 单元用例（CS-112 富文本改造）
 *
 * 正文的源改成 markdown 后，渲染器是「作者输入 → 落库 HTML」之间的第一道工序。
 * 这里锁定三件事：
 *   ① 设计文档 §4 承诺的能力（标题/段落/列表/表格/图片/链接/锚点）确实能渲染出来；
 *   ② 渲染器的安全配置生效（内嵌 HTML 转义、危险协议链接丢弃）；
 *   ③ 渲染产物再过 HtmlSanitizer 仍然稳定（不重复转义、幂等）—— 这是「写入侧净化」
 *      这条不变式在新管线里继续成立的前提。
 */
it('渲染设计文档承诺的结构：标题/段落/列表/表格/引用/代码', function () {
    $html = MarkdownRenderer::toHtml(
        "# 大标题\n\n段落文字。\n\n- 一\n- 二\n\n1. 甲\n2. 乙\n\n"
        ."| 列 A | 列 B |\n|---|---|\n| 1 | 2 |\n\n> 引用\n\n```php\necho 1;\n```"
    );

    expect($html)->toContain('<h1')
        ->toContain('<p>段落文字。</p>')
        ->toContain('<ul>')
        ->toContain('<li>一</li>')
        ->toContain('<ol>')
        ->toContain('<table>')
        ->toContain('<th>列 A</th>')
        ->toContain('<td>1</td>')
        ->toContain('<blockquote>')
        ->toContain('<pre>')
        // 代码块带语言 class（`<code class="language-php">`），故不断言 `<code>`
        ->toContain('<code');
});

it('中文标题生成可用的锚点 id（锚点能力在 markdown 下的落地方式）', function () {
    $html = MarkdownRenderer::toHtml('## 如何下单购买商品？');

    expect($html)->toContain('<h2 id="content-')
        ->toContain('如何下单购买商品')
        // 只加 id，不插入符号链接（insert=none）
        ->not->toContain('<a');
});

it('图片与链接正常渲染（含站内相对路径与 data:image）', function () {
    $html = MarkdownRenderer::toHtml(
        "[帮助中心](/service-center)  ![配图](/storage/uploads/a.png)\n\n![](data:image/png;base64,iVBORw0KGgo=)"
    );

    expect($html)->toContain('<a href="/service-center">帮助中心</a>')
        ->toContain('<img src="/storage/uploads/a.png"')
        ->toContain('src="data:image/png;base64,iVBORw0KGgo="');
});

it('内嵌原始 HTML 被转义成文本，不产出可执行标签', function () {
    $html = MarkdownRenderer::toHtml("<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n正常");

    expect($html)->not->toContain('<script')
        ->not->toContain('<img')
        // 是「转义」而不是「静默删除」，作者能看到自己写了什么
        ->toContain('&lt;script&gt;')
        ->toContain('&lt;img');
});

it('危险协议链接在渲染阶段就被丢弃', function () {
    expect(MarkdownRenderer::toHtml('[点我](javascript:alert(1))'))->not->toContain('javascript:')
        ->and(MarkdownRenderer::toHtml('[点我](data:text/html;base64,PHNjcmlwdD4=)'))->not->toContain('data:text/html')
        ->and(MarkdownRenderer::toHtml('![](javascript:alert(1))'))->not->toContain('javascript:');
});

it('删除线与自动链接（GFM）生效', function () {
    $html = MarkdownRenderer::toHtml("~~旧价~~ 与 https://example.com");

    expect($html)->toContain('<del>旧价</del>')
        ->toContain('href="https://example.com"');
});

it('空输入返回空串，不报错', function () {
    expect(MarkdownRenderer::toHtml(null))->toBe('')
        ->and(MarkdownRenderer::toHtml(''))->toBe('')
        ->and(MarkdownRenderer::toHtml("   \n  "))->toBe('');
});

it('渲染产物过 HtmlSanitizer 后稳定：不二次转义、幂等', function () {
    // 这条是「写入侧净化」不变式在新管线里成立的关键：
    // markdown 渲染出的实体（&lt;script&gt;）若再走 clean() 的纯文本分支会被二次转义成
    // &amp;lt;，所以模型写入器必须用 cleanHtml()。
    $rendered = MarkdownRenderer::toHtml("<script>alert(1)</script>\n\n**加粗**\n\n| a |\n|---|\n| 1 |");

    $once = \App\Support\HtmlSanitizer::cleanHtml($rendered);

    expect(\App\Support\HtmlSanitizer::cleanHtml($once))->toBe($once)
        ->and($once)->toContain('&lt;script&gt;')
        ->not->toContain('&amp;lt;')
        ->toContain('<strong>加粗</strong>')
        ->toContain('<table>');
});
