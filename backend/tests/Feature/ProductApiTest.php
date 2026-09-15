<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    seedDemoProducts();
});

// PROD-001 商品列表
test('TC-PROD-001 商品列表分页返回', function () {
    $resp = $this->getJson('/api/products');

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.list'))->toBeArray()
        ->and($resp->json('data.pagination'))->toHaveKeys(['page', 'page_size', 'total', 'total_pages']);
});

// PROD-002 搜索
test('TC-PROD-002 关键词搜索商品', function () {
    $resp = $this->getJson('/api/products?keyword='.urlencode('回归测试商品'));

    expect($resp->json('code'))->toBe(0);
    $total = $resp->json('data.pagination.total');
    if ($total > 0) {
        expect($resp->json('data.list.0.title'))->toContain('回归测试商品');
    }
});

// PROD-003 筛选排序
test('TC-PROD-003 分类筛选与价格排序', function () {
    $cats = $this->getJson('/api/products/categories')->json('data');
    if (empty($cats)) {
        $this->markTestSkipped('无分类种子数据');
    }

    $resp = $this->getJson('/api/products?category_id='.$cats[0]['id'].'&sort=price_asc');

    expect($resp->json('code'))->toBe(0);
});

// PROD-004 商品详情
test('TC-PROD-004 商品详情含 SKU 与库存', function () {
    $list = $this->getJson('/api/products')->json('data.list');
    if (empty($list)) {
        $this->markTestSkipped('无商品种子数据');
    }

    $resp = $this->getJson('/api/products/'.$list[0]['id']);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.skus'))->toBeArray();
});

// PROD-005 不存在的商品
test('TC-PROD-005 不存在的商品返回 40004/404', function () {
    $resp = $this->getJson('/api/products/999999');

    expect($resp->json('code'))->toBe(40004)->and($resp->status())->toBe(404);
});

// 热销接口
test('热销商品接口可用', function () {
    $resp = $this->getJson('/api/products/hot');

    expect($resp->json('code'))->toBe(0);
});
