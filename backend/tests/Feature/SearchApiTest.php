<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Common\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * 站内搜索接口（前台，站内搜索 S1-07）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §6
 *
 * 跑在 SQLite 上，此时引擎恒为 `FallbackLikeEngine` —— 也就是说这些用例
 * 钉的是**控制器契约**（入参归一化、出参装配、SEC-04、public_id 出口），
 * 不钉引擎行为（那部分由 S1-04/S1-05 的用例负责）。
 *
 * 最容易写错、也最值得钉住的三条：
 * 1. 对外标识一律 public_id（`related_categories.id`、`list[].id` 都必须是字符串）；
 * 2. `total` 在公开接口下受 SEC-04 管控（置 null 而非 0）—— 手搓分页结构时很容易漏；
 * 3. `related_categories` 与 `recommendations` **互斥**（非空结果 / 空结果），不能同时给。
 */
beforeEach(function () {
    Cache::flush();

    $this->category = Category::create(['parent_id' => 0, 'name' => '家具', 'sort' => 0, 'status' => 1]);
    $this->other = Category::create(['parent_id' => 0, 'name' => '数码', 'sort' => 0, 'status' => 1]);

    $this->make = function (array $attributes = []): Product {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'title' => '未命名',
            'price' => '100.00',
            'status' => 1,
        ], $attributes));
    };
});

test('TC-SEARCH-S1-07-001 /search 命中：list + pagination + meta.keyword', function () {
    ($this->make)(['title' => '北欧实木沙发']);
    ($this->make)(['title' => '不锈钢保温杯']);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发'));

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.list'))->toHaveCount(1)
        ->and($resp->json('data.list.0.title'))->toBe('北欧实木沙发')
        // P2-11：对外标识必须是 public_id（字符串），不是 int 主键
        ->and($resp->json('data.list.0.id'))->toBeString()
        ->and($resp->json('data.pagination'))->toHaveKeys(['page', 'page_size', 'total', 'total_pages', 'has_more'])
        ->and($resp->json('data.pagination.page'))->toBe(1)
        ->and($resp->json('data.meta.keyword'))->toBe('沙发');
});

test('TC-SEARCH-S1-07-002 公开接口不暴露精确 total（SEC-04）', function () {
    ($this->make)(['title' => '北欧实木沙发']);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发'));

    // 置 null 而非 0：0 会被前端误读成「真的没有」
    expect($resp->json('data.pagination.total'))->toBeNull()
        ->and($resp->json('data.pagination.total_pages'))->toBeNull();
});

test('TC-SEARCH-S1-07-003 非空结果给 related_categories（public_id + count）', function () {
    ($this->make)(['title' => '沙发 A']);
    ($this->make)(['title' => '沙发 B']);
    ($this->make)(['title' => '沙发 C', 'category_id' => $this->other->id]);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发'));

    $related = $resp->json('data.meta.related_categories');

    expect($related)->toBeArray()
        ->and($related[0]['id'])->toBe((string) $this->category->public_id)
        ->and($related[0]['name'])->toBe('家具')
        ->and($related[0]['count'])->toBe(2)
        // 互斥：有命中就不给推荐位
        ->and($resp->json('data.meta'))->not->toHaveKey('recommendations');
});

test('TC-SEARCH-S1-07-004 空结果给 recommendations（不白屏）', function () {
    ($this->make)(['title' => '北欧实木沙发', 'is_home_recommended' => true]);

    $resp = $this->getJson('/api/search?keyword='.urlencode('不存在的词xyz'));

    expect($resp->json('data.list'))->toBeArray()
        ->and($resp->json('data.list'))->toHaveCount(0)
        ->and($resp->json('data.meta.recommendations'))->not->toBeEmpty()
        ->and($resp->json('data.meta'))->not->toHaveKey('related_categories');
});

test('TC-SEARCH-S1-07-005 无关键词退化为浏览（browse），不报错', function () {
    ($this->make)(['title' => '沙发 A']);
    ($this->make)(['title' => '餐桌 B']);

    $resp = $this->getJson('/api/search');

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.list'))->toHaveCount(2)
        ->and($resp->json('data.meta.keyword'))->toBe('');
});

test('TC-SEARCH-S1-07-006 排序参数生效（price_asc / sales_desc）', function () {
    ($this->make)(['title' => '贵沙发', 'price' => '900.00']);
    ($this->make)(['title' => '便宜沙发', 'price' => '90.00']);

    $asc = $this->getJson('/api/search?keyword='.urlencode('沙发').'&sort=price_asc');
    expect($asc->json('data.list.0.title'))->toBe('便宜沙发');

    $desc = $this->getJson('/api/search?keyword='.urlencode('沙发').'&sort=price_desc');
    expect($desc->json('data.list.0.title'))->toBe('贵沙发');
});

test('TC-SEARCH-S1-07-007 分类筛选接受 public_id', function () {
    ($this->make)(['title' => '家具沙发']);
    ($this->make)(['title' => '数码沙发', 'category_id' => $this->other->id]);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发').'&category_id='.$this->other->public_id);

    expect($resp->json('data.list'))->toHaveCount(1)
        ->and($resp->json('data.list.0.title'))->toBe('数码沙发');
});

test('TC-SEARCH-S1-07-008 品牌筛选接受 public_id', function () {
    $brand = Brand::create(['name' => '顾家家居', 'sort' => 0, 'status' => 1]);
    ($this->make)(['title' => '沙发 A']);
    ($this->make)(['title' => '沙发 B', 'brand_id' => $brand->id]);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发').'&brand_id='.$brand->public_id);

    expect($resp->json('data.list'))->toHaveCount(1)
        ->and($resp->json('data.list.0.title'))->toBe('沙发 B');
});

test('TC-SEARCH-S1-07-009 page_size 上限收敛到 100（防深翻页拖垮 rank 计算）', function () {
    ($this->make)(['title' => '沙发 A']);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发').'&page_size=500');

    expect($resp->json('data.pagination.page_size'))->toBe(100);
});

test('TC-SEARCH-S1-07-010 分页：第二页不重不漏', function () {
    foreach (range(1, 5) as $i) {
        ($this->make)(['title' => '沙发'.$i]);
    }

    $first = $this->getJson('/api/search?keyword='.urlencode('沙发').'&page=1&page_size=2');
    $second = $this->getJson('/api/search?keyword='.urlencode('沙发').'&page=2&page_size=2');

    $a = collect($first->json('data.list'))->pluck('title')->all();
    $b = collect($second->json('data.list'))->pluck('title')->all();

    expect($first->json('data.pagination.has_more'))->toBeTrue()
        ->and($a)->toHaveCount(2)
        ->and($b)->toHaveCount(2)
        ->and(array_intersect($a, $b))->toBe([]);
});

test('TC-SEARCH-S1-07-011 /search/suggest 返回 string[]，含搜过的词', function () {
    // 标题是「北欧实木沙发」，不以「沙」开头，所以只能由①词频来源命中
    ($this->make)(['title' => '北欧实木沙发']);
    $this->getJson('/api/search?keyword='.urlencode('沙发'));

    $resp = $this->getJson('/api/search/suggest?keyword='.urlencode('沙'));

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data'))->toBeArray()
        ->and($resp->json('data'))->toContain('沙发');
});

test('TC-SEARCH-S1-07-012 /search/suggest 空关键词返回空数组', function () {
    $resp = $this->getJson('/api/search/suggest?keyword=');

    expect($resp->json('data'))->toBeArray()
        ->and($resp->json('data'))->toBeEmpty();
});

test('TC-SEARCH-S1-07-013 /search/hot 返回热搜词（有结果的搜索才上榜）', function () {
    ($this->make)(['title' => '北欧实木沙发']);

    $this->getJson('/api/search?keyword='.urlencode('沙发'));
    $this->getJson('/api/search?keyword='.urlencode('不存在的词xyz')); // 零结果，不该上榜

    $resp = $this->getJson('/api/search/hot?limit=10');

    expect($resp->json('data'))->toBeArray()
        ->and($resp->json('data'))->toContain('沙发')
        ->and($resp->json('data'))->not->toContain('不存在的词xyz');
});

test('TC-SEARCH-S1-07-014 诊断字段默认不暴露，开关打开后才给', function () {
    ($this->make)(['title' => '北欧实木沙发']);

    $default = $this->getJson('/api/search?keyword='.urlencode('沙发'));
    expect($default->json('data.meta'))->not->toHaveKey('engine')
        ->and($default->json('data.meta'))->not->toHaveKey('relaxed');

    app(ConfigService::class)->set('search.expose_debug', '1');
    Cache::flush();

    $debug = $this->getJson('/api/search?keyword='.urlencode('沙发'));
    expect($debug->json('data.meta'))->toHaveKeys(['engine', 'relaxed']);

    app(ConfigService::class)->set('search.expose_debug', '0');
});

test('TC-SEARCH-S1-07-015 /products 兼容：关键词搜索与分页结构不变', function () {
    ($this->make)(['title' => '北欧实木沙发']);
    ($this->make)(['title' => '不锈钢保温杯']);

    $resp = $this->getJson('/api/products?keyword='.urlencode('沙发'));

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.list'))->toHaveCount(1)
        ->and($resp->json('data.list.0.title'))->toBe('北欧实木沙发')
        ->and($resp->json('data.pagination'))->toHaveKeys(['page', 'page_size', 'total', 'total_pages', 'has_more'])
        // /products 是原有的兼容入口，不该多出 meta
        ->and($resp->json('data'))->not->toHaveKey('meta');
});

test('TC-SEARCH-S1-07-016 /products 未传 sort 时保持原默认序（sort desc + id desc）', function () {
    ($this->make)(['title' => '沙发 A', 'sort' => 1]);
    ($this->make)(['title' => '沙发 B', 'sort' => 9]);

    $resp = $this->getJson('/api/products?keyword='.urlencode('沙发'));

    // 旧实现：orderByDesc('sort')->orderByDesc('id')，rank 更高的先出
    expect($resp->json('data.list.0.title'))->toBe('沙发 B');

    // 显式传 sort 仍然生效
    $sales = $this->getJson('/api/products?keyword='.urlencode('沙发').'&sort=price_asc');
    expect($sales->json('code'))->toBe(0);
});

test('TC-SEARCH-S1-07-017 /products 无关键词仍是全量浏览', function () {
    ($this->make)(['title' => '沙发 A']);
    ($this->make)(['title' => '餐桌 B']);

    $resp = $this->getJson('/api/products');

    expect($resp->json('data.list'))->toHaveCount(2);
});

test('TC-SEARCH-S1-07-019 分类出口带 public_id（预加载列漏了 public_id 会让前端拿不到分类 id）', function () {
    ($this->make)(['title' => '沙发 A']);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发'));

    expect($resp->json('data.list.0.category.id'))->toBe((string) $this->category->public_id)
        ->and($resp->json('data.list.0.category.name'))->toBe('家具');
});

test('TC-SEARCH-S1-07-018 下架商品不出现在搜索结果里', function () {
    ($this->make)(['title' => '在售沙发', 'status' => 1]);
    ($this->make)(['title' => '下架沙发', 'status' => 0]);

    $resp = $this->getJson('/api/search?keyword='.urlencode('沙发'));

    expect(collect($resp->json('data.list'))->pluck('title')->all())->toBe(['在售沙发']);
});
