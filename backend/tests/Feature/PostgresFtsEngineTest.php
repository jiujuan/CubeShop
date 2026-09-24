<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Search\PostgresFtsEngine;
use App\Support\Search\SearchCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * PG 全文检索引擎（站内搜索 S1-04）
 *
 * ⚠️ 可用性判定跟随**当前连接驱动**：SQLite 下 `search_vector` 生成列不存在恒不可用；
 * PG 回归库（phpunit.pgsql.xml）驱动与生成列均就绪则可用。与向量有关的真行为由
 * S1-11 的 PG 特性测试 cover（开发期已在真库手工验过）；两种驱动下共同钉住的是：
 * tsquery 字面量构造（最容易出语法/注入问题的地方）、可用性判定的驱动条件、
 * 联想、移除、以及「不可用时必须抛异常而不是静默返回空」这条约定。
 */
beforeEach(function () {
    PostgresFtsEngine::flushAvailabilityCache();

    $this->engine = app(PostgresFtsEngine::class);
});

test('TC-SEARCH-S1-04-011 可用性判定跟随驱动：非 pgsql 判不可用，pgsql + 生成列就绪判可用', function () {
    $isPgsql = DB::connection()->getDriverName() === 'pgsql';

    expect($this->engine->isAvailable())->toBe($isPgsql)
        ->and($this->engine->name())->toBe('postgres');
});

test('TC-SEARCH-S1-04-012 不可用时 search 抛异常而非静默返回空', function () {
    if ($this->engine->isAvailable()) {
        // PG 回归库上前提不成立（驱动与生成列都就绪）；「不可用」语义由 SQLite 环境回归钉住
        $this->markTestSkipped('当前驱动即 pgsql 且生成列已建，无可用的不可用场景');
    }

    // 静默返回空会表现为「搜什么都搜不到」，是最难排查的故障形态，必须响亮地失败
    $this->engine->search(SearchCriteria::fromArray(['keyword' => '北欧沙发']));
})->throws(RuntimeException::class);

test('TC-SEARCH-S1-04-013 AND 查询：token 全部包成单引号字面量', function () {
    expect(PostgresFtsEngine::buildTsquery(['北欧', '欧沙', '沙发']))
        ->toBe("'北欧' & '欧沙' & '沙发'");
});

test('TC-SEARCH-S1-04-014 OR 查询（AND 零结果后的放宽）', function () {
    expect(PostgresFtsEngine::buildTsquery(['北欧', '沙发'], 'or'))
        ->toBe("'北欧' | '沙发'");
});

test('TC-SEARCH-S1-04-015 前缀匹配只作用于最后一个 token，:* 在引号外', function () {
    expect(PostgresFtsEngine::buildTsquery(['iphone', '15'], 'and', prefix: true))
        ->toBe("'iphone' & '15':*")
        // 单词场景
        ->and(PostgresFtsEngine::buildTsquery(['iphon'], prefix: true))->toBe("'iphon':*");
});

test('TC-SEARCH-S1-04-016 tsquery 语法字符必须被引号吃掉（否则 500 或语义被改）', function () {
    // & | ! ( ) : * 在 tsquery 里都是操作符，不包引号会直接语法错误
    expect(PostgresFtsEngine::buildTsquery(['c++', 'a&b', 'x|y', 'z:w']))
        ->toBe("'c++' & 'a&b' & 'x|y' & 'z:w'");
});

test('TC-SEARCH-S1-04-017 单引号按 PG 规则转义成两个单引号', function () {
    expect(PostgresFtsEngine::buildTsquery(["men's"]))->toBe("'men''s'");
});

test('TC-SEARCH-S1-04-018 空 token 与纯空白被丢弃，全空返回空串', function () {
    expect(PostgresFtsEngine::buildTsquery([]))->toBe('')
        ->and(PostgresFtsEngine::buildTsquery(['', '  ', '沙发']))->toBe("'沙发'");
});

test('TC-SEARCH-S1-04-019 联想：商品标题前缀优先，其次分类名，再品牌名', function () {
    $category = Category::create(['parent_id' => 0, 'name' => '北欧家具', 'sort' => 0, 'status' => 1]);
    Brand::create(['name' => '北欧印象', 'logo' => null, 'sort' => 0, 'status' => 1]);

    Product::create([
        'category_id' => $category->id,
        'title' => '北欧实木沙发',
        'price' => '1999.00',
        'status' => 1,
        'sales_count' => 5,
    ]);
    Product::create([
        'category_id' => $category->id,
        'title' => '北欧餐桌',
        'price' => '999.00',
        'status' => 1,
        'sales_count' => 50,
    ]);
    // 下架商品不该进联想
    Product::create(['category_id' => $category->id, 'title' => '北欧下架品', 'price' => '1.00', 'status' => 0]);

    $suggestions = $this->engine->suggest('北欧', 10);

    expect($suggestions)->toContain('北欧餐桌')
        ->and($suggestions)->toContain('北欧实木沙发')
        ->and($suggestions)->toContain('北欧家具')
        ->and($suggestions)->toContain('北欧印象')
        ->and($suggestions)->not->toContain('北欧下架品')
        // 热销的排在前面
        ->and(array_search('北欧餐桌', $suggestions, true))
        ->toBeLessThan(array_search('北欧实木沙发', $suggestions, true));
});

test('TC-SEARCH-S1-04-020 联想：空词与 limit<=0 返回空，不去查库', function () {
    expect($this->engine->suggest('', 10))->toBe([])
        ->and($this->engine->suggest('   ', 10))->toBe([])
        ->and($this->engine->suggest('北欧', 0))->toBe([]);
});

test('TC-SEARCH-S1-04-021 联想条数受 limit 约束', function () {
    $category = Category::create(['parent_id' => 0, 'name' => '沙发', 'sort' => 0, 'status' => 1]);

    foreach (['沙发 A', '沙发 B', '沙发 C'] as $title) {
        Product::create(['category_id' => $category->id, 'title' => $title, 'price' => '1.00', 'status' => 1]);
    }

    expect(count($this->engine->suggest('沙发', 2)))->toBeLessThanOrEqual(2);
});

test('TC-SEARCH-S1-04-022 remove 清空两列（PG 下生成列随之变空，不再被召回）', function () {
    $product = Product::create([
        'category_id' => Category::create(['parent_id' => 0, 'name' => '沙发', 'sort' => 0, 'status' => 1])->id,
        'title' => '北欧实木沙发',
        'price' => '1.00',
        'status' => 1,
    ]);

    expect($product->search_title)->not->toBeNull();

    $this->engine->remove($product->id);

    $after = Product::find($product->id);
    expect($after->search_title)->toBeNull()
        ->and($after->search_body)->toBeNull()
        // 只是移出索引，商品本身还在
        ->and($after->title)->toBe('北欧实木沙发');
});

test('TC-SEARCH-S1-04-023 reindex 委托给写入器，可把 remove 掉的商品加回索引', function () {
    $product = Product::create([
        'category_id' => Category::create(['parent_id' => 0, 'name' => '沙发', 'sort' => 0, 'status' => 1])->id,
        'title' => '北欧实木沙发',
        'price' => '1.00',
        'status' => 1,
    ]);

    $this->engine->remove($product->id);
    $this->engine->reindex($product->id);

    expect(Product::find($product->id)->search_title)->toBe('北欧 欧实 实木 木沙 沙发');
});

test('TC-SEARCH-S1-04-024 reindexAll 全量重算并回报条数', function () {
    $category = Category::create(['parent_id' => 0, 'name' => '沙发', 'sort' => 0, 'status' => 1]);

    foreach (['北欧沙发', '真皮沙发'] as $title) {
        Product::create(['category_id' => $category->id, 'title' => $title, 'price' => '1.00', 'status' => 1]);
    }

    Product::query()->update(['search_title' => null, 'search_body' => null]);

    $progress = [];
    $count = $this->engine->reindexAll(1, function (int $scanned, int $updated) use (&$progress): void {
        $progress[] = [$scanned, $updated];
    });

    expect($count)->toBe(2)
        ->and($progress)->not->toBeEmpty()
        ->and(Product::whereNotNull('search_title')->count())->toBe(2);
});
