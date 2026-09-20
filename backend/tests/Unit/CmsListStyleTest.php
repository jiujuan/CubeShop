<?php

use App\Support\CmsListStyle;

/**
 * 栏目列表形态真源（CMS 新闻中心，一期）守卫
 *
 * 与 CmsPageTemplate / ConfigGroup 同体例：前后台取值共用一份契约，改动即校验，防止漂移。
 */
test('CmsListStyle 值集合与标签', function () {
    expect(CmsListStyle::values())->toBe([CmsListStyle::CARD, CmsListStyle::LIST]);
    expect(CmsListStyle::LABELS)->toHaveKey(CmsListStyle::CARD)
        ->and(CmsListStyle::LABELS)->toHaveKey(CmsListStyle::LIST);
    expect(CmsListStyle::label(CmsListStyle::CARD))->toBe('图文卡片');
    expect(CmsListStyle::label(CmsListStyle::LIST))->toBe('列表行');
});

test('CmsListStyle isValid / DEFAULT', function () {
    expect(CmsListStyle::isValid('card'))->toBeTrue()
        ->and(CmsListStyle::isValid('list'))->toBeTrue()
        ->and(CmsListStyle::isValid('banner'))->toBeFalse();
    // 未知值回落默认
    expect(CmsListStyle::label('nope'))->toBe(CmsListStyle::LABELS[CmsListStyle::DEFAULT]);
    expect(CmsListStyle::DEFAULT)->toBe('list');
});
