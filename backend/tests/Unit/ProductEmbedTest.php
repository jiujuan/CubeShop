<?php

use App\Support\ProductEmbed;

/**
 * 正文内联商品卡标记（`[[product:{public_id}]]`）
 *
 * 这类不依赖数据库，纯字符串解析，单独放 Unit 里跑得快；
 * 「渲染 → 净化 → 落库 → 出口」的链路行为在 tests/Feature/NewsApiTest.php 与
 * tests/Feature/AdminCsFaqApiTest.php 里覆盖。
 */
it('独占一段的标记换成占位容器，商品标识用 public_id', function () {
    $html = ProductEmbed::tokenize("<p>前言</p>\n<p>[[product:01HXABC]]</p>\n<p>后记</p>");

    expect($html)->toContain('<div id="news-product-01HXABC">')
        // 标记本身要消失，否则前台会看到字面文本
        ->and($html)->not->toContain('[[product:')
        // 前后段落原样保留（只在标记处动手）
        ->and($html)->toContain('<p>前言</p>')
        ->and($html)->toContain('<p>后记</p>');
});

it('行内出现的标记按字面保留（不替换成块级容器，避免破坏段落结构）', function () {
    $html = '<p>这款 [[product:01HXABC]] 不错</p>';

    expect(ProductEmbed::tokenize($html))->toBe($html);
});

it('多个标记各占一段时逐个替换', function () {
    $html = ProductEmbed::tokenize("<p>[[product:A1]]</p><p>中间</p><p>[[product:B2]]</p>");

    expect($html)->toContain('<div id="news-product-A1">')
        ->and($html)->toContain('<div id="news-product-B2">');
});

it('extractIds 按出现顺序取出去重后的内联商品', function () {
    $html = ProductEmbed::tokenize("<p>[[product:A1]]</p><p>[[product:B2]]</p><p>[[product:A1]]</p>");

    expect(ProductEmbed::extractIds($html))->toBe(['A1', 'B2']);
});

it('extractIds 对空内容与无占位正文返回空数组', function () {
    expect(ProductEmbed::extractIds(null))->toBe([])
        ->and(ProductEmbed::extractIds(''))->toBe([])
        ->and(ProductEmbed::extractIds('<p>纯正文</p>'))->toBe([]);
});

it('extractTokenIds 解析 markdown 源（行内也算，用于保存时并入关联）', function () {
    expect(ProductEmbed::extractTokenIds("看看 [[product:A1]]\n\n[[product:B2]]\n\n[[product:A1]]"))
        ->toBe(['A1', 'B2'])
        ->and(ProductEmbed::extractTokenIds(null))->toBe([])
        ->and(ProductEmbed::extractTokenIds('没有标记'))->toBe([]);
});

it('tokenize 对 null / 空串 / 无标记正文原样返回', function () {
    expect(ProductEmbed::tokenize(null))->toBe('')
        ->and(ProductEmbed::tokenize(''))->toBe('')
        ->and(ProductEmbed::tokenize('<p>纯正文</p>'))->toBe('<p>纯正文</p>');
});

it('占位容器带可见回退文案，前台未渲染（或后台预览）时看得见这里有一张卡', function () {
    $html = ProductEmbed::tokenize('<p>[[product:A1]]</p>');

    expect($html)->toContain(ProductEmbed::FALLBACK_LABEL)
        ->and(ProductEmbed::placeholderId('A1'))->toBe('news-product-A1');
});
