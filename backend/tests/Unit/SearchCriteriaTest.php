<?php

use App\Support\Search\SearchCriteria;

/**
 * 检索条件 DTO（站内搜索 S1-04）
 *
 * 纯值对象，不碰数据库 —— 缓存签名稳定性与入参归一化都是最容易悄悄劣化的地方，
 * 一旦签名不稳定，表现是「缓存命中率莫名掉到 0」，很难从线上现象反推。
 */
test('TC-SEARCH-S1-04-001 默认值：无关键词时按上新排，分页 1/20', function () {
    $criteria = SearchCriteria::fromArray([]);

    expect($criteria->keyword)->toBe('')
        ->and($criteria->hasKeyword())->toBeFalse()
        ->and($criteria->sort)->toBe('newest')
        ->and($criteria->sortByRelevance())->toBeFalse()
        ->and($criteria->page)->toBe(1)
        ->and($criteria->pageSize)->toBe(20)
        ->and($criteria->categoryId)->toBeNull()
        ->and($criteria->brandId)->toBeNull()
        ->and($criteria->attributeValues)->toBe([]);
});

test('TC-SEARCH-S1-04-002 关键词两侧空白被 trim，有关键词默认按相关度', function () {
    $criteria = SearchCriteria::fromArray(['keyword' => '  北欧沙发  ']);

    expect($criteria->keyword)->toBe('北欧沙发')
        ->and($criteria->hasKeyword())->toBeTrue()
        ->and($criteria->sort)->toBe('relevance')
        ->and($criteria->sortByRelevance())->toBeTrue();
});

test('TC-SEARCH-S1-04-003 分页边界：页码下限 1、每页条数 1~100', function () {
    expect(SearchCriteria::fromArray(['page' => 0])->page)->toBe(1)
        ->and(SearchCriteria::fromArray(['page' => -3])->page)->toBe(1)
        ->and(SearchCriteria::fromArray(['page_size' => 0])->pageSize)->toBe(20)
        ->and(SearchCriteria::fromArray(['page_size' => -5])->pageSize)->toBe(20)
        ->and(SearchCriteria::fromArray(['page_size' => 100])->pageSize)->toBe(100)
        ->and(SearchCriteria::fromArray(['page_size' => 999])->pageSize)->toBe(100);
});

test('TC-SEARCH-S1-04-004 价格：空串与负数落为 null（不当成 0 参与筛选）', function () {
    $criteria = SearchCriteria::fromArray(['min_price' => '', 'max_price' => '-1']);

    expect($criteria->minPrice)->toBeNull()
        ->and($criteria->maxPrice)->toBeNull()
        ->and(SearchCriteria::fromArray(['min_price' => '99.5'])->minPrice)->toBe(99.5);
});

test('TC-SEARCH-S1-04-005 属性筛选解析：同属性合并，脏行跳过', function () {
    $criteria = SearchCriteria::fromArray([
        'attribute_values' => [
            '3:实木',
            '3:布艺',
            '7:红色',
            '没有冒号',
            '0:非法属性',
            '5:',
        ],
    ]);

    expect($criteria->attributeValues)->toBe([3 => ['实木', '布艺'], 7 => ['红色']]);
});

test('TC-SEARCH-S1-04-006 排序白名单外的取值一律回落（不拼进 ORDER BY）', function () {
    expect(SearchCriteria::fromArray(['sort' => 'id; DROP TABLE'])->sort)->toBe('newest')
        ->and(SearchCriteria::fromArray(['sort' => 'id; DROP TABLE', 'keyword' => 'x'])->sort)->toBe('relevance')
        ->and(SearchCriteria::fromArray(['sort' => 'price_asc'])->sort)->toBe('price_asc')
        ->and(SearchCriteria::fromArray(['sort' => 'sales_desc'])->sort)->toBe('sales_desc');
});

test('TC-SEARCH-S1-04-007 缓存签名稳定：同条件同值，不同条件不同值', function () {
    $a = SearchCriteria::fromArray(['keyword' => '北欧沙发', 'page' => 1, 'page_size' => 20]);
    $b = SearchCriteria::fromArray(['keyword' => '北欧沙发', 'page' => 1, 'page_size' => 20]);
    $c = SearchCriteria::fromArray(['keyword' => '北欧沙发', 'page' => 2, 'page_size' => 20]);

    expect($a->signature())->toBe($b->signature())
        ->and($a->signature())->not->toBe($c->signature())
        ->and($a->signature())->toMatch('/^[0-9a-f]{32}$/');
});

test('TC-SEARCH-S1-04-008 属性多值的书写顺序不影响签名（防缓存穿透）', function () {
    $a = SearchCriteria::fromArray(['keyword' => '沙发', 'attribute_values' => ['3:实木', '3:布艺', '7:红']]);
    $b = SearchCriteria::fromArray(['keyword' => '沙发', 'attribute_values' => ['7:红', '3:布艺', '3:实木']]);

    expect($a->signature())->toBe($b->signature());
});

test('TC-SEARCH-S1-04-009 重复属性值去重后仍等价', function () {
    $a = SearchCriteria::fromArray(['keyword' => '沙发', 'attribute_values' => ['3:实木', '3:实木']]);
    $b = SearchCriteria::fromArray(['keyword' => '沙发', 'attribute_values' => ['3:实木']]);

    expect($a->signature())->toBe($b->signature());
});

test('TC-SEARCH-S1-04-010 withKeyword 只换关键词，其余筛选原样保留', function () {
    $criteria = SearchCriteria::fromArray([
        'keyword' => '北欧沙发',
        'category_id' => 12,
        'brand_id' => 7,
        'min_price' => '100',
        'page_size' => 30,
    ]);

    $swapped = $criteria->withKeyword('保护套');

    expect($swapped->keyword)->toBe('保护套')
        ->and($swapped->categoryId)->toBe(12)
        ->and($swapped->brandId)->toBe(7)
        ->and($swapped->minPrice)->toBe(100.0)
        ->and($swapped->pageSize)->toBe(30)
        // 原对象不被改动（只读语义）
        ->and($criteria->keyword)->toBe('北欧沙发');
});
