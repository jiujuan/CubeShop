<?php

use App\Jobs\Search\UpdateSearchKeyword;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\SearchKeyword;
use App\Services\Product\ProductSearchService;
use App\Support\Search\SearchCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 商品检索服务（站内搜索 S1-06）
 *
 * 设计文档 §4.4 / §4.6 / §4.7 / §6。跑在 SQLite 上 —— 此时引擎恒为 `FallbackLikeEngine`，
 * 于是「搜索」这条链第一次能在不依赖 PG 的环境里端到端跑通。
 *
 * 钉住的四件事：**缓存**、**降级链编排**、**重排**、**词频投递**，
 * 外加一条最容易写错的约定 —— `total` 必须是**筛选后**的总数。
 */
beforeEach(function () {
    Cache::flush();

    $this->service = app(ProductSearchService::class);
    $this->category = Category::create(['parent_id' => 0, 'name' => '家具', 'sort' => 0, 'status' => 1]);

    $this->make = function (array $attributes = []): Product {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'title' => '未命名',
            'price' => '100.00',
            'status' => 1,
        ], $attributes));
    };
});

/** @param  array<string, mixed>  $params */
function searchWith(array $params): \App\Support\Search\SearchPage
{
    return app(ProductSearchService::class)->search(SearchCriteria::fromArray($params));
}

test('TC-SEARCH-S1-06-001 无关键词走浏览链路：不过引擎，engine=none', function () {
    ($this->make)(['title' => '沙发 A']);
    ($this->make)(['title' => '餐桌 B']);

    $page = searchWith(['keyword' => '']);

    expect($page->total)->toBe(2)
        ->and($page->engine)->toBe('none')
        ->and($page->relaxed)->toBeFalse()
        ->and($page->relatedCategories)->toBe([]);
});

test('TC-SEARCH-S1-06-002 有关键词：命中并按相关度排序（标题命中优先于副标题）', function () {
    $titleHit = ($this->make)(['title' => '沙发']);
    ($this->make)(['title' => '北欧桌', 'subtitle' => '配沙发']);

    $page = searchWith(['keyword' => '沙发']);

    expect($page->total)->toBe(2)
        ->and($page->items->first()->id)->toBe($titleHit->id)
        ->and($page->engine)->toBe('like');
});

test('TC-SEARCH-S1-06-003 total 是筛选后的总数，不是引擎命中数', function () {
    $brand = Brand::create(['name' => '顾家', 'logo' => null, 'sort' => 0, 'status' => 1]);

    ($this->make)(['title' => '沙发 A', 'brand_id' => $brand->id]);
    ($this->make)(['title' => '沙发 B']);
    ($this->make)(['title' => '沙发 C']);

    // 引擎命中 3 条，品牌筛选后只剩 1 条 —— total 必须是后者，否则翻页必然错位
    $page = searchWith(['keyword' => '沙发', 'brand_id' => $brand->id]);

    expect($page->total)->toBe(1)
        ->and($page->items->count())->toBe(1);
});

test('TC-SEARCH-S1-06-004 分类筛选含直接子类', function () {
    $child = Category::create(['parent_id' => $this->category->id, 'name' => '沙发', 'sort' => 0, 'status' => 1]);
    $other = Category::create(['parent_id' => 0, 'name' => '厨具', 'sort' => 0, 'status' => 1]);

    $inChild = ($this->make)(['title' => '沙发 child', 'category_id' => $child->id]);
    ($this->make)(['title' => '沙发 other', 'category_id' => $other->id]);

    $page = searchWith(['keyword' => '沙发', 'category_id' => $this->category->id]);

    expect($page->total)->toBe(1)
        ->and($page->items->first()->id)->toBe($inChild->id);
});

test('TC-SEARCH-S1-06-005 价格区间筛选', function () {
    ($this->make)(['title' => '沙发 便宜', 'price' => '99.00']);
    ($this->make)(['title' => '沙发 昂贵', 'price' => '9999.00']);

    expect(searchWith(['keyword' => '沙发', 'min_price' => '500'])->total)->toBe(1)
        ->and(searchWith(['keyword' => '沙发', 'max_price' => '500'])->total)->toBe(1)
        ->and(searchWith(['keyword' => '沙发', 'min_price' => '500', 'max_price' => '600'])->total)->toBe(0);
});

test('TC-SEARCH-S1-06-006 属性筛选：同属性多值 OR，跨属性 AND', function () {
    $material = Attribute::create(['name' => '材质', 'type' => Attribute::TYPE_PARAM, 'sort' => 0]);
    $color = Attribute::create(['name' => '颜色', 'type' => Attribute::TYPE_PARAM, 'sort' => 0]);

    $cotton = ($this->make)(['title' => '沙发 棉麻']);
    $leather = ($this->make)(['title' => '沙发 真皮']);
    ProductAttributeValue::create(['product_id' => $cotton->id, 'attribute_id' => $material->id, 'value' => '棉麻']);
    ProductAttributeValue::create(['product_id' => $cotton->id, 'attribute_id' => $color->id, 'value' => '米白']);
    ProductAttributeValue::create(['product_id' => $leather->id, 'attribute_id' => $material->id, 'value' => '真皮']);

    $values = [$material->id.':棉麻', $material->id.':真皮'];

    expect(searchWith(['keyword' => '沙发', 'attribute_values' => $values])->total)->toBe(2)
        // 跨属性 AND：材质匹配但要米白，真皮款落选
        ->and(searchWith(['keyword' => '沙发', 'attribute_values' => [...$values, $color->id.':米白']])->total)->toBe(1);
});

test('TC-SEARCH-S1-06-007 分页：切片正确且 total 不随页码变', function () {
    foreach (range(1, 5) as $i) {
        ($this->make)(['title' => "沙发 {$i}"]);
    }

    $first = searchWith(['keyword' => '沙发', 'page' => 1, 'page_size' => 2]);
    $second = searchWith(['keyword' => '沙发', 'page' => 2, 'page_size' => 2]);
    $last = searchWith(['keyword' => '沙发', 'page' => 3, 'page_size' => 2]);

    expect($first->items->count())->toBe(2)
        ->and($second->items->count())->toBe(2)
        ->and($last->items->count())->toBe(1)
        ->and($first->total)->toBe(5)
        ->and($second->total)->toBe(5)
        ->and($first->lastPage())->toBe(3)
        // 两页不能有重复
        ->and(array_intersect($first->items->pluck('id')->all(), $second->items->pluck('id')->all()))->toBe([]);
});

test('TC-SEARCH-S1-06-008 sort 覆盖相关度：price_asc 生效', function () {
    ($this->make)(['title' => '沙发 贵', 'price' => '900.00']);
    ($this->make)(['title' => '沙发 便宜', 'price' => '90.00']);

    $page = searchWith(['keyword' => '沙发', 'sort' => 'price_asc']);

    expect($page->items->first()->price)->toBe('90.00')
        ->and($page->items->last()->price)->toBe('900.00');
});

test('TC-SEARCH-S1-06-009 缓存：命中集被缓存，新增商品在 TTL 内不可见', function () {
    ($this->make)(['title' => '沙发 A']);

    expect(searchWith(['keyword' => '沙发'])->total)->toBe(1);

    ($this->make)(['title' => '沙发 B']);

    // 缓存未失效 → 仍是 1；这正是「只缓存 id 列表」的代价，靠 TTL 与 index_version 收敛
    expect(searchWith(['keyword' => '沙发'])->total)->toBe(1);

    Cache::flush();

    expect(searchWith(['keyword' => '沙发'])->total)->toBe(2);
});

test('TC-SEARCH-S1-06-010 空结果：返回推荐位而不是白屏', function () {
    $hot = ($this->make)(['title' => '热销桌', 'sales_count' => 50]);

    $page = searchWith(['keyword' => '绝对不存在的词xyz']);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->items)->toHaveCount(0)
        ->and($page->recommendations->pluck('id'))->toContain($hot->id)
        ->and($page->relatedCategories)->toBe([]);
});

test('TC-SEARCH-S1-06-011 有结果的搜索投递词频（Job 执行后落库）', function () {
    Queue::fake();

    ($this->make)(['title' => '沙发 A']);
    searchWith(['keyword' => '沙发']);

    Queue::assertPushed(UpdateSearchKeyword::class);
});

test('TC-SEARCH-S1-06-012 词频落库：同 IP 同词节流，换 IP 再计一次', function () {
    ($this->make)(['title' => '沙发 A']);

    $this->service->search(SearchCriteria::fromArray(['keyword' => '沙发']), '1.2.3.4');
    expect(SearchKeyword::where('keyword', '沙发')->value('hit_count'))->toBe(1);

    // 同一 IP + 同一词 60s 内不再计
    $this->service->search(SearchCriteria::fromArray(['keyword' => '沙发']), '1.2.3.4');
    expect(SearchKeyword::where('keyword', '沙发')->value('hit_count'))->toBe(1);

    $this->service->search(SearchCriteria::fromArray(['keyword' => '沙发']), '5.6.7.8');
    expect(SearchKeyword::where('keyword', '沙发')->value('hit_count'))->toBe(2);
});

test('TC-SEARCH-S1-06-013 零结果不记词频（避免脏词污染热搜榜）', function () {
    $this->service->search(SearchCriteria::fromArray(['keyword' => '不存在的词']), '1.2.3.4');

    expect(SearchKeyword::count())->toBe(0);
});

test('TC-SEARCH-S1-06-014 词频归一化：全角与空白折叠后算同一个词', function () {
    ($this->make)(['title' => '沙发 A']);

    $this->service->search(SearchCriteria::fromArray(['keyword' => '沙发　A']), '1.2.3.4');
    $this->service->search(SearchCriteria::fromArray(['keyword' => '沙发 A']), '9.9.9.9');

    expect(SearchKeyword::count())->toBe(1)
        ->and(SearchKeyword::first()->keyword)->toBe('沙发 A');
});

test('TC-SEARCH-S1-06-015 热搜榜按 hit_count 降序，屏蔽词不进榜', function () {
    SearchKeyword::create(['keyword' => '沙发', 'hit_count' => 10, 'status' => SearchKeyword::STATUS_ACTIVE]);
    SearchKeyword::create(['keyword' => '餐桌', 'hit_count' => 30, 'status' => SearchKeyword::STATUS_ACTIVE]);
    SearchKeyword::create(['keyword' => '敏感词', 'hit_count' => 99, 'status' => SearchKeyword::STATUS_BLOCKED]);

    expect($this->service->hotKeywords(10))->toBe(['餐桌', '沙发']);
});

test('TC-SEARCH-S1-06-016 相关分类按命中数聚合，对外只给 public_id', function () {
    $sofaCategory = Category::create(['parent_id' => 0, 'name' => '沙发类', 'sort' => 0, 'status' => 1]);

    ($this->make)(['title' => '北欧沙发 1', 'category_id' => $sofaCategory->id]);
    ($this->make)(['title' => '北欧沙发 2', 'category_id' => $sofaCategory->id]);
    ($this->make)(['title' => '北欧桌']);

    $page = searchWith(['keyword' => '北欧']);

    $names = array_column($page->relatedCategories, 'name');
    $counts = array_column($page->relatedCategories, 'count');

    expect($names[0])->toBe('沙发类')
        ->and($counts[0])->toBe(2)
        ->and($page->relatedCategories[0]['id'])->toBe($sofaCategory->public_id)
        // public_id 是字符串，不是内部 int 主键
        ->and($page->relatedCategories[0]['id'])->not->toBe($sofaCategory->id);
});

test('TC-SEARCH-S1-06-017 单字中文查询走 LIKE 引擎（降级链第 0 步）', function () {
    $hit = ($this->make)(['title' => '单人休闲椅']);

    $page = searchWith(['keyword' => '椅']);

    expect($page->engine)->toBe('like')
        ->and($page->items->pluck('id')->all())->toBe([$hit->id]);
});

test('TC-SEARCH-S1-06-018 UpdateSearchKeyword 累加计数并刷新最近结果数', function () {
    UpdateSearchKeyword::dispatchSync('沙发', 3);
    UpdateSearchKeyword::dispatchSync('沙发', 7);

    $row = SearchKeyword::where('keyword', '沙发')->first();

    expect($row->hit_count)->toBe(2)
        ->and($row->result_count)->toBe(7)
        ->and($row->status)->toBe(SearchKeyword::STATUS_ACTIVE)
        ->and($row->last_hit_at)->not->toBeNull();
});

test('TC-SEARCH-S1-06-019 联想委托当前引擎', function () {
    ($this->make)(['title' => '北欧餐桌']);

    expect($this->service->suggest('北欧', 10))->toContain('北欧餐桌');
});

test('TC-SEARCH-S1-06-020 下架商品不进搜索结果', function () {
    ($this->make)(['title' => '沙发 上架']);
    ($this->make)(['title' => '沙发 下架', 'status' => 0]);

    expect(searchWith(['keyword' => '沙发'])->total)->toBe(1);
});
