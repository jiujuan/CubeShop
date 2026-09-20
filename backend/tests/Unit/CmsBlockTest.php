<?php

use App\Support\CmsBlock;
use App\Support\CmsField;
use App\Support\CmsPageTemplate;

/**
 * CMS-203：区块注册表
 *
 * ⚠️ 本文件不定义全局函数（与其他测试文件重名会导致全量跑 Cannot redeclare fatal）。
 */
it('区块真源自身合法：类型在白名单内、select 都给了 options', function () {
    $schemas = [];
    foreach (CmsBlock::BLOCKS as $key => $block) {
        $schemas['block:'.$key] = $block['fields'];
    }
    foreach (CmsPageTemplate::TEMPLATES as $key => $template) {
        $schemas['template:'.$key] = $template['fields'];
    }

    expect(CmsField::inspect($schemas))->toBe([]);
});

it('每个区块都有对应的前台组件（web/src/views/blocks/Block{Key}.vue）', function () {
    // 守卫体例同 CmsPageTemplateTest TC-CMS-T08：真源新增区块却漏写组件 → 直接失败
    $dir = dirname(base_path()).'/web/src/views/blocks';
    expect(is_dir($dir))->toBeTrue("缺少前台区块组件目录：{$dir}");

    $files = array_map('basename', glob($dir.'/Block*.vue') ?: []);
    $expected = array_map(fn (string $key) => CmsBlock::componentName($key), CmsBlock::blockKeys());

    // 双向比对：真源多一个（漏写组件）或少一个（组件没登记）都要报错
    expect(array_values(array_intersect($expected, $files)))->toEqualCanonicalizing($expected);
    expect(array_values(array_diff($files, $expected)))->toBe([]);
});

it('区块 key 到组件名的转换覆盖下划线命名', function () {
    expect(CmsBlock::componentName('hero'))->toBe('BlockHero.vue')
        ->and(CmsBlock::componentName('text_image'))->toBe('BlockTextImage.vue')
        ->and(CmsBlock::componentName('faq_embed'))->toBe('BlockFaqEmbed.vue');
});

it('filterPayload 丢弃未知区块类型', function () {
    $result = CmsBlock::filterPayload([
        ['type' => 'hero', 'data' => ['title' => '标题']],
        ['type' => 'not-exist', 'data' => ['x' => 1]],
        'not-an-array',
    ]);

    expect($result)->toHaveCount(1)
        ->and($result[0]['type'])->toBe('hero');
});

it('filterPayload 逐块按各自 schema 过滤字段并补默认值', function () {
    $result = CmsBlock::filterPayload([
        ['type' => 'hero', 'data' => ['title' => '欢迎', 'evil' => 'x']],
        ['type' => 'text_image', 'data' => ['text' => '正文']],
    ]);

    // hero 只保留自己的字段，未知键丢弃
    expect($result[0]['data'])->toHaveKey('title')->not->toHaveKey('evil')
        ->and($result[0]['data']['title'])->toBe('欢迎');

    // text_image 未提供的 select 字段拿到默认值 left
    expect($result[1]['data']['side'])->toBe('left')
        ->and($result[1]['data'])->toHaveKeys(['title', 'text', 'image', 'side']);
});

it('filterPayload 截断到上限，防止塞进几百个区块', function () {
    $blocks = array_fill(0, CmsBlock::MAX_BLOCKS + 5, ['type' => 'rich_text', 'data' => ['body' => 'x']]);

    expect(CmsBlock::filterPayload($blocks))->toHaveCount(CmsBlock::MAX_BLOCKS);
});

it('非数组入参得到空数组（脏数据不进库）', function () {
    expect(CmsBlock::filterPayload(null))->toBe([])
        ->and(CmsBlock::filterPayload('oops'))->toBe([]);
});

it('indexedRules 展开成逐块规则，未知类型被 Rule::in 拦下', function () {
    $rules = CmsBlock::indexedRules([
        ['type' => 'hero', 'data' => []],
        ['type' => 'gallery', 'data' => []],
    ]);

    expect($rules)->toHaveKey('blocks')
        ->and($rules)->toHaveKey('blocks.0.type')
        ->and($rules)->toHaveKey('blocks.1.type')
        // hero 的字段规则挂在第 0 块下
        ->and($rules)->toHaveKey('blocks.0.data.title')
        // gallery 的字段规则挂在第 1 块下，不会串到第 0 块
        ->and($rules)->toHaveKey('blocks.1.data.images')
        ->and($rules)->not->toHaveKey('blocks.0.data.images');
});

it('indexedRules 对 hero 的必填标题产出 required，非必填产出 nullable', function () {
    $rules = CmsBlock::indexedRules([['type' => 'hero', 'data' => []]]);

    expect($rules['blocks.0.data.title'])->toContain('required')
        ->and($rules['blocks.0.data.subtitle'])->toContain('nullable');
});

it('select 字段的规则限定在 options 取值内', function () {
    $rules = CmsBlock::indexedRules([['type' => 'text_image', 'data' => []]]);
    $side = $rules['blocks.0.data.side'];

    expect($side)->toContain('nullable')
        ->and(collect($side)->contains(fn ($r) => is_object($r) && str_contains((string) $r, 'in')))->toBeTrue();
});

it('区块模板是合法模板 key，且字段为空', function () {
    expect(CmsPageTemplate::isBlocks(CmsPageTemplate::TEMPLATE_BLOCKS))->toBeTrue()
        ->and(CmsPageTemplate::exists(CmsPageTemplate::TEMPLATE_BLOCKS))->toBeTrue()
        ->and(CmsPageTemplate::schema(CmsPageTemplate::TEMPLATE_BLOCKS))->toBe([])
        ->and(CmsPageTemplate::filterPayload(CmsPageTemplate::TEMPLATE_BLOCKS, ['x' => 1]))->toBe([]);
});

it('一期固定模板行为不变（重构 CmsField 后回归）', function () {
    $rules = CmsPageTemplate::rules('about');

    expect($rules['fields.intro'])->toContain('required')
        ->and($rules['fields.banner'])->toContain('nullable')
        ->and($rules['fields.milestones'])->toContain('array')
        ->and(CmsPageTemplate::defaultsOf('about')['milestones'])->toBe([])
        ->and(CmsPageTemplate::filterPayload('about', ['intro' => 'x', 'evil' => 1]))
        ->toBe(['banner' => '', 'intro' => 'x', 'milestones' => [], 'values' => []]);
});
