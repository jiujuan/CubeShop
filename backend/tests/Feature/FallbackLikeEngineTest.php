<?php

use App\Models\Category;
use App\Models\Product;
use App\Support\Search\FallbackLikeEngine;
use App\Support\Search\SearchCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * LIKE 降级引擎（站内搜索 S1-05）
 *
 * 设计文档 §4.4 降级链 / §9 测试策略。这里跑在 SQLite 上 —— 降级引擎的意义恰恰是
 * 「在没有 PG 全文能力的环境里也能搜」，所以它必须能在 SQLite 下完整验证。
 *
 * 核心断言分两类：
 * 1. **与现状 LIKE 逐条一致**（`/products?keyword=` 的行为不能被降级引擎改坏）；
 * 2. **比现状更安全**（通配符转义、放宽链、评分排序是新增的，不是回归面）。
 */
beforeEach(function () {
    $this->engine = app(FallbackLikeEngine::class);
    $this->category = Category::create(['parent_id' => 0, 'name' => '家具', 'sort' => 0, 'status' => 1]);
});

/** @param  array<string, mixed>  $attributes */
function makeSearchProduct(Category $category, array $attributes): Product
{
    return Product::create(array_merge([
        'category_id' => $category->id,
        'title' => '未命名',
        'price' => '1.00',
        'status' => 1,
    ], $attributes));
}

test('TC-SEARCH-S1-05-001 降级引擎恒可用，名字为 like（它是最后一道闸）', function () {
    expect($this->engine->isAvailable())->toBeTrue()
        ->and($this->engine->name())->toBe('like');
});

test('TC-SEARCH-S1-05-002 单词查询与现状 LIKE 完全一致（title 命中）', function () {
    $hit = makeSearchProduct($this->category, ['title' => '北欧实木沙发']);
    $miss = makeSearchProduct($this->category, ['title' => '北欧餐桌']);

    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '沙发']));

    expect($result->ids)->toBe([$hit->id])
        ->and($result->total)->toBe(1)
        ->and($result->relaxed)->toBeFalse()
        ->and($result->engine)->toBe('like')
        ->and($result->ids)->not->toContain($miss->id);
});

test('TC-SEARCH-S1-05-003 副标题命中（现状 LIKE 的第二个字段）', function () {
    $hit = makeSearchProduct($this->category, [
        'title' => '客厅三人位',
        'subtitle' => '可拆洗布艺沙发',
    ]);

    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '沙发']));

    expect($result->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-004 单字中文查询命中（降级链第 0 步的落点）', function () {
    $hit = makeSearchProduct($this->category, ['title' => '单人休闲椅']);

    // 「椅」在 PG 引擎里零命中（S1-04 真机实测），正是靠本引擎兜住
    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '椅']));

    expect($result->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-005 英文大小写不敏感', function () {
    $hit = makeSearchProduct($this->category, ['title' => 'iPhone 15 保护壳']);

    expect($this->engine->search(SearchCriteria::fromArray(['keyword' => 'iphone']))->ids)->toBe([$hit->id])
        ->and($this->engine->search(SearchCriteria::fromArray(['keyword' => 'IPHONE']))->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-006 下架商品不召回（status=1 是唯一的过滤条件）', function () {
    makeSearchProduct($this->category, ['title' => '下架沙发', 'status' => 0]);

    expect($this->engine->search(SearchCriteria::fromArray(['keyword' => '沙发']))->ids)->toBe([]);
});

test('TC-SEARCH-S1-05-007 空关键词与纯符号返回空结果，不查库', function () {
    expect($this->engine->search(SearchCriteria::fromArray([]))->ids)->toBe([])
        ->and($this->engine->search(SearchCriteria::fromArray(['keyword' => '   ']))->ids)->toBe([])
        ->and($this->engine->search(SearchCriteria::fromArray(['keyword' => '，。！']))->ids)->toBe([]);
});

test('TC-SEARCH-S1-05-008 多词查询：AND 零结果自动放宽为 OR 并标记 relaxed', function () {
    $sofa = makeSearchProduct($this->category, ['title' => '北欧实木沙发']);
    $table = makeSearchProduct($this->category, ['title' => '北欧餐桌']);

    // 「北欧沙发」分词成 北欧/欧沙/沙发，AND 必然落空（没有商品同时含这三个 bigram）
    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '北欧沙发']));

    expect($result->relaxed)->toBeTrue()
        ->and($result->ids)->toContain($sofa->id)
        ->and($result->ids)->toContain($table->id)
        // 覆盖率高的排前面：沙发命中 2/3，餐桌只命中 1/3
        ->and($result->ids[0])->toBe($sofa->id);
});

test('TC-SEARCH-S1-05-009 AND 能命中时不做放宽（精确率优先）', function () {
    $hit = makeSearchProduct($this->category, ['title' => '北欧实木沙发']);
    makeSearchProduct($this->category, ['title' => '北欧餐桌']);

    // 北欧 + 沙发 两个 token 都在标题里 → AND 直接命中，不触发放宽
    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '北欧 沙发']));

    expect($result->relaxed)->toBeFalse()
        ->and($result->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-010 分词后无 token（纯英文停用词）时退回整串，不劣化现状', function () {
    $hit = makeSearchProduct($this->category, ['title' => 'The And Chair']);
    makeSearchProduct($this->category, ['title' => 'The Or Table']);

    // 停用词被分词器吃光 → 若直接返回空，`/products?keyword=the and` 就从有结果变成零结果
    expect($this->engine->search(SearchCriteria::fromArray(['keyword' => 'the and']))->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-011 百分号必须转义：搜 % 只命中含字面百分号的商品', function () {
    $hit = makeSearchProduct($this->category, ['title' => '50% 羊毛衫']);
    $miss = makeSearchProduct($this->category, ['title' => '纯羊毛衫']);

    // 不转义时 `%` 是通配符，会命中全部商品（且退化为全表扫描）
    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '%']));

    expect($result->ids)->toBe([$hit->id])
        ->and($result->ids)->not->toContain($miss->id);
});

test('TC-SEARCH-S1-05-012 下划线必须转义：搜 _ 只命中含字面下划线的商品', function () {
    $hit = makeSearchProduct($this->category, ['title' => 'A_B 收纳盒']);
    makeSearchProduct($this->category, ['title' => 'AB 收纳盒']);

    expect($this->engine->search(SearchCriteria::fromArray(['keyword' => '_']))->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-013 反斜杠本身不破坏查询（转义符自身被转义）', function () {
    $hit = makeSearchProduct($this->category, ['title' => 'C:\\tmp 支架']);

    expect($this->engine->search(SearchCriteria::fromArray(['keyword' => '\\']))->ids)->toBe([$hit->id]);
});

test('TC-SEARCH-S1-05-014 评分：标题命中排在仅副标题命中之前', function () {
    $titleHit = makeSearchProduct($this->category, ['title' => '沙发']);
    makeSearchProduct($this->category, ['title' => '北欧桌', 'subtitle' => '配沙发']);

    $result = $this->engine->search(SearchCriteria::fromArray(['keyword' => '沙发']));

    expect($result->ids[0])->toBe($titleHit->id);
});

test('TC-SEARCH-S1-05-015 同分时销量高的排前面', function () {
    makeSearchProduct($this->category, ['title' => '沙发 A', 'sales_count' => 1]);
    $hot = makeSearchProduct($this->category, ['title' => '沙发 B', 'sales_count' => 99]);

    expect($this->engine->search(SearchCriteria::fromArray(['keyword' => '沙发']))->ids[0])->toBe($hot->id);
});

test('TC-SEARCH-S1-05-016 联想：商品标题前缀优先，下架不参与', function () {
    makeSearchProduct($this->category, ['title' => '北欧餐桌', 'sales_count' => 10]);
    makeSearchProduct($this->category, ['title' => '北欧实木沙发', 'sales_count' => 1]);
    makeSearchProduct($this->category, ['title' => '北欧下架品', 'status' => 0]);

    $suggestions = $this->engine->suggest('北欧', 10);

    expect($suggestions)->toContain('北欧餐桌')
        ->and($suggestions)->toContain('北欧实木沙发')
        ->and($suggestions)->not->toContain('北欧下架品')
        ->and(array_search('北欧餐桌', $suggestions, true))
        ->toBeLessThan(array_search('北欧实木沙发', $suggestions, true));
});

test('TC-SEARCH-S1-05-017 联想：商品不足时由分类名、品牌名补齐', function () {
    makeSearchProduct($this->category, ['title' => '北欧餐桌']);
    Category::create(['parent_id' => 0, 'name' => '北欧风', 'sort' => 0, 'status' => 1]);

    expect($this->engine->suggest('北欧', 5))->toContain('北欧风');
});

test('TC-SEARCH-S1-05-018 联想：空词与 limit<=0 返回空', function () {
    expect($this->engine->suggest('', 10))->toBe([])
        ->and($this->engine->suggest('   ', 10))->toBe([])
        ->and($this->engine->suggest('北欧', 0))->toBe([]);
});

test('TC-SEARCH-S1-05-019 remove 清空索引列，商品本身保留（与 PG 引擎同语义）', function () {
    $product = makeSearchProduct($this->category, ['title' => '北欧实木沙发']);

    expect($product->search_title)->not->toBeNull();

    $this->engine->remove($product->id);

    $after = Product::find($product->id);

    expect($after->search_title)->toBeNull()
        ->and($after->search_body)->toBeNull()
        ->and($after->title)->toBe('北欧实木沙发');
});

test('TC-SEARCH-S1-05-020 reindex 可把 remove 掉的商品加回索引', function () {
    $product = makeSearchProduct($this->category, ['title' => '北欧实木沙发']);
    $this->engine->remove($product->id);

    $this->engine->reindex($product->id);

    expect(Product::find($product->id)->search_title)->not->toBeNull();
});

test('TC-SEARCH-S1-05-021 reindexAll 覆盖全部商品（含软删），实测写回行数', function () {
    makeSearchProduct($this->category, ['title' => '甲']);
    makeSearchProduct($this->category, ['title' => '乙']);
    $trashed = makeSearchProduct($this->category, ['title' => '丙']);
    $trashed->delete();

    // 先人为清空，否则幂等逻辑认为「值没变」直接跳过，测不出覆盖
    Product::withTrashed()->update(['search_title' => null, 'search_body' => null]);

    $touched = $this->engine->reindexAll(50);

    expect($touched)->toBe(3)
        ->and(Product::withTrashed()->whereNull('search_title')->count())->toBe(0);
});
