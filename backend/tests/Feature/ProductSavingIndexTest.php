<?php

use App\Jobs\Search\ReindexProductsByBrandOrCategory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSku;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 站内搜索 S1-03：索引同步契约
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.3
 *
 * 这里钉的是「什么时候该重算、什么时候不该」——重算漏了会搜不到新内容，
 * 重算多了则每次改价/改状态都白跑一遍分词和 4 次关联查询。
 */
beforeEach(function () {
    $this->writer = app(SearchIndexWriter::class);

    $this->category = Category::create(['parent_id' => 0, 'name' => '实木沙发', 'sort' => 0, 'status' => 1]);
    $this->brand = Brand::create(['name' => '顾家家居', 'logo' => null, 'sort' => 0, 'status' => 1]);

    $this->makeProduct = function (array $overrides = []): Product {
        return Product::create([
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'title' => '北欧实木沙发',
            'subtitle' => '客厅小户型',
            'keywords' => '布艺,可拆洗',
            'price' => '1999.00',
            'status' => 1,
            ...$overrides,
        ]);
    };
});

test('TC-SEARCH-S1-03-001 新建商品自动派生检索列', function () {
    $product = ($this->makeProduct)();

    expect($product->search_title)->toBe('北欧 欧实 实木 木沙 沙发')
        ->and($product->search_body)->not->toBeNull();
});

test('TC-SEARCH-S1-03-002 改标题后检索列同步更新', function () {
    $product = ($this->makeProduct)();

    $product->title = '真皮转角沙发';
    $product->save();

    expect($product->fresh()->search_title)->toBe('真皮 皮转 转角 角沙 沙发');
});

test('TC-SEARCH-S1-03-003 改详情正文不触发检索列重算', function () {
    $product = ($this->makeProduct)();
    $before = $product->search_title;

    // 正文不进索引（bigram 会让 tsvector 膨胀数倍），改它不该动检索列
    $product->description_md = '# 全新详情\n\n很长的正文';
    $product->save();

    expect($product->fresh()->search_title)->toBe($before);
});

test('TC-SEARCH-S1-03-004 改价格/状态这类无关保存不重算', function () {
    $product = ($this->makeProduct)();

    // 人为打一个"脏标记"：若无关保存触发了重算，它会被正确值覆盖掉
    Product::whereKey($product->id)->update(['search_title' => 'STALE']);
    $product->refresh();

    $product->price = '2999.00';
    $product->status = 0;
    $product->save();

    expect($product->fresh()->search_title)->toBe('STALE', '改价格/状态不该触发重算');
});

test('TC-SEARCH-S1-03-005 换品牌/换分类后正文域跟着变', function () {
    $product = ($this->makeProduct)();
    expect(explode(' ', $product->search_body))->toContain('顾家');

    $another = Brand::create(['name' => '林氏木业', 'logo' => null, 'sort' => 0, 'status' => 1]);
    $product->brand_id = $another->id;
    $product->save();

    $tokens = explode(' ', $product->fresh()->search_body);
    expect($tokens)->toContain('林氏')
        ->and($tokens)->not->toContain('顾家');
});

test('TC-SEARCH-S1-03-006 存量行（检索列为空）借任意一次保存补上', function () {
    $product = ($this->makeProduct)();
    Product::whereKey($product->id)->update(['search_title' => null, 'search_body' => null]);
    $product->refresh();

    $product->price = '3999.00'; // 本该是"无关保存"
    $product->save();

    expect($product->fresh()->search_title)->toBe('北欧 欧实 实木 木沙 沙发');
});

test('TC-SEARCH-S1-03-007 改品牌名派发级联 Job', function () {
    Queue::fake();

    $this->brand->name = '顾家家居官方';
    $this->brand->save();

    Queue::assertPushed(ReindexProductsByBrandOrCategory::class, function (ReindexProductsByBrandOrCategory $job): bool {
        return $job->type === ReindexProductsByBrandOrCategory::TYPE_BRAND
            && $job->id === $this->brand->id;
    });
});

test('TC-SEARCH-S1-03-008 改品牌排序/状态不派发 Job', function () {
    Queue::fake();

    $this->brand->sort = 9;
    $this->brand->status = 0;
    $this->brand->save();

    Queue::assertNothingPushed();
});

test('TC-SEARCH-S1-03-009 改品牌名后该品牌下商品索引真的更新（sync 队列）', function () {
    $product = ($this->makeProduct)();
    expect(explode(' ', $product->search_body))->toContain('顾家');

    $this->brand->name = '顾家家居新';
    $this->brand->save();

    // 旧名「顾家家居」→ 新名「顾家家居新」：新 token「居新」必须出现
    $tokens = explode(' ', $product->fresh()->search_body);
    expect($tokens)->toContain('顾家')
        ->and($tokens)->toContain('居新');

    // 再换一个完全不同的名字，旧词必须彻底消失（否则说明重算没落库）
    $this->brand->name = '林氏木业';
    $this->brand->save();

    expect(explode(' ', $product->fresh()->search_body))->not->toContain('顾家');
});

test('TC-SEARCH-S1-03-010 改分类名后该分类下商品索引更新', function () {
    $product = ($this->makeProduct)();
    expect(explode(' ', $product->search_body))->toContain('实木');

    $this->category->name = '岩板餐桌';
    $this->category->save();

    $tokens = explode(' ', $product->fresh()->search_body);
    expect($tokens)->toContain('岩板')
        ->and($tokens)->toContain('餐桌')
        ->and($tokens)->not->toContain('沙发');
});

test('TC-SEARCH-S1-03-011 开关关闭时改名不派发也不重算', function () {
    config(['services.search.index_taxonomy_names' => false]);

    $product = ($this->makeProduct)();
    $before = $product->search_body;

    Queue::fake();

    $this->brand->name = '另一个名字';
    $this->brand->save();

    // 钩子先看开关：索引里没有品牌名时连 Job 都不该派发
    Queue::assertNothingPushed();

    expect($product->fresh()->search_body)->toBe($before);
});

test('TC-SEARCH-S1-03-012 SKU 编码进了索引（reindex 后可见）', function () {
    $product = ($this->makeProduct)();

    // 建商品时 SKU 还没落库，索引里自然没有编码 —— 这正是后台要在事务末尾 reindex 的原因
    expect(explode(' ', $product->search_body))->not->toContain('sf');

    ProductSku::create([
        'product_id' => $product->id,
        'sku_code' => 'SF-001',
        'price' => '1999.00',
        'status' => 1,
    ]);

    $this->writer->reindex($product);

    $tokens = explode(' ', $product->fresh()->search_body);
    expect($tokens)->toContain('sf')
        ->and($tokens)->toContain('001');
});

test('TC-SEARCH-S1-03-013 reindex 用库里最新关联，不用调用方手上的旧缓存', function () {
    $product = ($this->makeProduct)();

    // 先让 $product 缓存一份"没有 SKU"的关联
    $product->load(SearchIndexWriter::INDEX_RELATIONS);
    expect($product->skus)->toHaveCount(0);

    ProductSku::create([
        'product_id' => $product->id,
        'sku_code' => 'SF-002',
        'price' => '1999.00',
        'status' => 1,
    ]);

    // 传模型实例也要重取，否则会算出「缺 SKU 编码」的索引
    $this->writer->reindex($product);

    expect(explode(' ', $product->fresh()->search_body))->toContain('002');
});

test('TC-SEARCH-S1-03-014 软删商品的索引也会被级联重算', function () {
    $product = ($this->makeProduct)();
    $product->delete();

    $this->brand->name = '软删后改名';
    $this->brand->save();

    $tokens = explode(' ', Product::withTrashed()->find($product->id)->search_body);
    expect($tokens)->toContain('软删')
        ->and($tokens)->toContain('改名');
});
