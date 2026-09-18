<?php

use App\Support\HtmlSanitizer;

/**
 * 富文本白名单净化器单测（CS-112 缺陷 #1）
 *
 * 帮助中心正文以 HTML 富文本维护、前端用 v-html 渲染，所以净化器是 XSS 的唯一防线。
 * 这里覆盖三类行为：① 危险内容必须被剥掉；② 设计文档承诺的富文本能力（段落/列表/
 * 表格/图片/锚点）必须保留；③ 纯文本与行内片段的换行不能被 HTML 折叠掉。
 */

it('丢弃脚本、事件属性与危险属性', function (string $input, string $secret) {
    $output = HtmlSanitizer::clean($input);

    expect($output)->not->toContain($secret)
        ->and($output)->not->toContain('<script')
        ->and($output)->not->toContain('onerror')
        ->and($output)->not->toContain('onclick')
        ->and($output)->not->toContain('style=')
        ->and($output)->not->toContain('class=');
})->with([
    ['<p>安全</p><script>alert(1)</script>', 'alert(1)'],
    ['<img src="/a.png" onerror="alert(2)">', 'alert(2)'],
    ['<p onclick="alert(3)">文字</p>', 'alert(3)'],
    ['<p style="position:fixed;top:0" class="evil">文字</p>', 'position:fixed'],
    ['<style>body{display:none}</style><p>文字</p>', 'display:none'],
    ['<svg><script>alert(4)</script></svg><p>文字</p>', 'alert(4)'],
]);

it('剥离 javascript: 等危险协议，保留合法链接', function () {
    expect(HtmlSanitizer::clean('<a href="javascript:alert(1)">点我</a>'))->toBe('<a>点我</a>');
    expect(HtmlSanitizer::clean('<a href="java script:alert(1)">点我</a>'))->toBe('<a>点我</a>');
    expect(HtmlSanitizer::clean('<img src="data:text/html;base64,PHNjcmlwdD4=">'))->toBe('<img>');

    // 合法：站内相对路径 / http(s) / 协议相对 / 图片 data:image
    expect(HtmlSanitizer::clean('<a href="/service-center">帮助</a>'))->toBe('<a href="/service-center">帮助</a>');
    expect(HtmlSanitizer::clean('<a href="https://example.com/x">外链</a>'))->toBe('<a href="https://example.com/x">外链</a>');
    expect(HtmlSanitizer::clean('<img src="/storage/uploads/a.png">'))->toBe('<img src="/storage/uploads/a.png">');
    expect(HtmlSanitizer::clean('<img src="data:image/png;base64,iVBORw0KGgo=">'))->toBe('<img src="data:image/png;base64,iVBORw0KGgo=">');
});

it('保留设计文档承诺的富文本能力：段落/标题/列表/表格/图片/锚点', function () {
    expect(HtmlSanitizer::clean('<p>第一段</p><p>第二段<br>换行</p>'))->toBe('<p>第一段</p><p>第二段<br>换行</p>');

    expect(HtmlSanitizer::clean('<h2 id="faq-1">小节</h2>'))
        ->toBe('<h2 id="faq-1">小节</h2>');

    expect(HtmlSanitizer::clean('<ul><li>一</li><li>二</li></ul>'))
        ->toBe('<ul><li>一</li><li>二</li></ul>');

    expect(HtmlSanitizer::clean('<table><thead><tr><th colspan="2">表头</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>'))
        ->toBe('<table><thead><tr><th colspan="2">表头</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>');

    expect(HtmlSanitizer::clean('<img src="/storage/a.png" alt="图" width="120">'))
        ->toBe('<img src="/storage/a.png" alt="图" width="120">');

    expect(HtmlSanitizer::clean('<blockquote>引用</blockquote><pre><code>code()</code></pre>'))
        ->toBe('<blockquote>引用</blockquote><pre><code>code()</code></pre>');
});

it('外链新窗口打开时自动补 rel，防止 Reverse Tabnabbing', function () {
    expect(HtmlSanitizer::clean('<a href="https://example.com" target="_blank">外链</a>'))
        ->toBe('<a href="https://example.com" target="_blank" rel="noopener noreferrer">外链</a>');

    // target 只允许 _blank / _self
    expect(HtmlSanitizer::clean('<a href="/x" target="evil">内链</a>'))->toBe('<a href="/x">内链</a>');
});

it('未知标签去壳保留文字，危险标签连子树一起丢弃', function () {
    expect(HtmlSanitizer::clean('<section><font color="red">保留文字</font></section>'))->toBe('保留文字');
    expect(HtmlSanitizer::clean('<iframe src="//evil.com"></iframe>正文'))->toBe('正文');
});

it('纯文本与行内片段的换行不被折叠', function () {
    // 完全无标签 → 转义 + <br>
    expect(HtmlSanitizer::clean("第一行\n第二行"))->toBe("第一行<br>\n第二行");
    // 有标签但全是行内标签 → 同样保留换行（否则作者写的多行会挤成一行）
    expect(HtmlSanitizer::clean("前文\n<strong>加粗</strong>"))->toBe("前文<br>\n<strong>加粗</strong>");
    // 含块级标签 → 视为已按 HTML 排版，原样保留
    expect(HtmlSanitizer::clean("<p>第一行\n第二行</p>"))->toBe("<p>第一行\n第二行</p>");
});

it('纯文本内容被转义，不可能变成标签', function () {
    $output = HtmlSanitizer::clean("a < b & c");

    expect($output)->toBe('a &lt; b &amp; c');
});

it('净化是幂等的（存量数据清洗可安全重复执行）', function () {
    $input = '<p>安全</p><script>x</script><img src="/a.png" onerror="x"><h2 id="ok">标题</h2>';

    $once = HtmlSanitizer::clean($input);

    expect(HtmlSanitizer::clean($once))->toBe($once);
});

it('空内容与非法 id 的处理', function () {
    expect(HtmlSanitizer::clean(''))->toBe('');
    expect(HtmlSanitizer::clean(null))->toBe('');

    // id 只允许字母开头 + 字母数字下划线冒号点横线，用作锚点
    expect(HtmlSanitizer::clean('<h2 id="a b">x</h2>'))->toBe('<h2>x</h2>');
    expect(HtmlSanitizer::clean('<h2 id="faq_1-2">x</h2>'))->toBe('<h2 id="faq_1-2">x</h2>');
});
