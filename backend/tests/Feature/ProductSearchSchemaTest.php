<?php

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductSku;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * 站内搜索 S1-02：检索列 schema + 字段构建 + 存量回填
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2 / §9
 *
 * ⚠️ 测试固定跑 SQLite，PG 的 `search_vector` 生成列与 GIN 在这里**不存在**，
 * 因此对 PG 专属部分只做「存在性条件断言」，不做行为断言 ——
 * 生成列的真实行为只能靠 PG 特性测试（S1-11）cover。这里要保证的是：
 * 通用两列、字段构建、回填幂等，这些在两种驱动下都必须成立。
 */
beforeEach(function () {
    $this->writer = app(SearchIndexWriter::class);

    $this->category = Category::create(['parent_id' => 0, 'name' => '实木沙发', 'sort' => 0, 'status' => 1]);
    $this->brand = Brand::create(['name' => '顾家家居', 'logo' => null, 'sort' => 0, 'status' => 1]);

    $this->product = Product::create([
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'title' => '北欧实木沙发',
        'subtitle' => '客厅小户型',
        'keywords' => '布艺,可拆洗',
        'price' => '1999.00',
        'status' => 1,
    ]);

    ProductSku::create([
        'product_id' => $this->product->id,
        'sku_code' => 'SF-001',
        'price' => '1999.00',
        'status' => 1,
    ]);

    $attribute = Attribute::create(['name' => '材质', 'type' => Attribute::TYPE_PARAM, 'sort' => 0]);

    ProductAttributeValue::create([
        'product_id' => $this->product->id,
        'attribute_id' => $attribute->id,
        'value' => '实木',
    ]);

    // 关联重新载入（创建时用的是 new 出来的模型实例，关系为空）
    $this->product->load(SearchIndexWriter::INDEX_RELATIONS);
});

test('TC-SEARCH-S1-02-001 检索两列建成，PG 下另有生成列', function () {
    expect(Schema::hasColumn('products', 'search_title'))->toBeTrue('缺 search_title')
        ->and(Schema::hasColumn('products', 'search_body'))->toBeTrue('缺 search_body');

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(Schema::hasColumn('products', 'search_vector'))->toBeTrue('PG 下缺 search_vector 生成列');
    }
});

test('TC-SEARCH-S1-02-002 字段构建覆盖全部来源', function () {
    $fields = $this->writer->fields($this->product);

    // 标题域只放 title：北欧实木沙发 → bigram
    expect($fields['search_title'])->toBe('北欧 欧实 实木 木沙 沙发');

    $body = $fields['search_body'];

    // 副标题 / 关键词 / 品牌 / 分类 / SKU 编码 / 参数值，一个都不能少。
    // 按 token 数组比对而非字符串包含 —— 后者会让「顾」这种单字被「顾家」蒙混过关。
    $tokens = explode(' ', $body);

    foreach (['客厅', '厅小', '小户', '户型', '布艺', '可拆', '拆洗',
        '顾家', '家家', '家居', '实木', '木沙', '沙发', 'sf', '001'] as $token) {
        expect($tokens)->toContain($token);
    }

    // 标题不进正文域（权重 A/B 要能分开，重复收录会让 ts_rank 失真）
    expect($tokens)->not->toContain('北欧');
});

test('TC-SEARCH-S1-02-003 不产生跨来源 bigram', function () {
    // 品牌「顾家家居」与分类「实木沙发」相邻，若拼接不用空格就会切出「家实」这种假词
    expect($this->writer->buildBodyField($this->product))
        ->not->toContain('家实')
        ->and($this->writer->buildBodyField($this->product))->not->toContain('居实');
});

test('TC-SEARCH-S1-02-004 开关关闭后品牌/分类名不进索引', function () {
    config(['services.search.index_taxonomy_names' => false]);

    $tokens = explode(' ', $this->writer->buildBodyField($this->product));

    expect($tokens)->not->toContain('顾家')
        ->and($tokens)->not->toContain('家居')
        // 分类名整体消失（「沙发」「木沙」只可能来自分类名），其余来源不受影响
        ->and($tokens)->not->toContain('沙发')
        ->and($tokens)->not->toContain('木沙')
        ->and($tokens)->toContain('布艺')
        ->and($tokens)->toContain('sf')
        // 参数值「实木」与分类名首词重合，但来自 product_attribute_values，必须保留
        ->and($tokens)->toContain('实木');
});

test('TC-SEARCH-S1-02-005 构建是纯函数（同输入恒同输出，回填幂等的地基）', function () {
    expect($this->writer->fields($this->product))->toBe($this->writer->fields($this->product));
});

test('TC-SEARCH-S1-02-006 存量回填迁移写入且可重复执行', function () {
    $id = $this->product->id;

    // S1-03 之后新建商品会由 saving 钩子自动派生，故先手工清空，模拟"迁移前的存量行"
    Product::whereKey($id)->update(['search_title' => null, 'search_body' => null]);
    expect(Product::find($id)->search_title)->toBeNull('存量行检索列应为空，回填才有意义');

    $run = function (): void {
        (require database_path('migrations/2026_09_24_000120_backfill_product_search_columns.php'))->up();
    };

    $run();

    $after = Product::find($id);
    expect($after->search_title)->toBe('北欧 欧实 实木 木沙 沙发')
        ->and($after->search_body)->not->toBeNull();

    // 幂等：第二次执行值不变，且不刷 updated_at
    $updatedAtBefore = $after->updated_at?->format('Y-m-d H:i:s');
    $run();

    $again = Product::find($id);
    expect($again->search_title)->toBe($after->search_title)
        ->and($again->search_body)->toBe($after->search_body)
        ->and($again->updated_at?->format('Y-m-d H:i:s'))->toBe($updatedAtBefore);
});

test('TC-SEARCH-S1-02-007 reindex 写入且不刷 updated_at', function () {
    $id = $this->product->id;
    $before = Product::find($id);
    $updatedAtBefore = $before->updated_at?->format('Y-m-d H:i:s');

    $this->writer->reindex($id);

    $after = Product::find($id);
    expect($after->search_title)->toBe('北欧 欧实 实木 木沙 沙发')
        ->and($after->updated_at?->format('Y-m-d H:i:s'))->toBe($updatedAtBefore);
});

test('TC-SEARCH-S1-02-008 reindex 对不存在的商品静默返回', function () {
    $this->writer->reindex(999999);

    expect(Product::find(999999))->toBeNull();
});

test('TC-SEARCH-S1-02-009 HTML 与实体不污染索引', function () {
    $product = Product::create([
        'category_id' => $this->category->id,
        'title' => '<div>棉麻<br>沙发</div>',
        'subtitle' => '小米&amp;华为',
        'price' => '1.00',
        'status' => 1,
    ]);

    // strip_tags 后标签消失，标签两侧的字连成一段 → bigram 会补出「麻沙」
    expect($this->writer->buildTitleField($product))->toBe('棉麻 麻沙 沙发')
        ->and($this->writer->buildBodyField($product))->not->toContain('div')
        ->and($this->writer->buildBodyField($product))->not->toContain('amp')
        // 实体反转义后「小米&华为」被切成两个词，而不是粘成一个
        ->and($this->writer->buildBodyField($product))->toContain('小米');
});

test('TC-SEARCH-S1-02-010 截断以整 token 为粒度，不切半个词', function () {
    // 2000 个互不相同的连续汉字 —— bigram 产出 1999 个互不相同的 token，
    // 远超标题域上限，必定触发截断（用重复词会被去重吃掉，测不出截断）
    $chars = [];
    for ($i = 0; $i < 2000; $i++) {
        $chars[] = mb_chr(0x4E00 + $i, 'UTF-8');
    }

    $product = Product::create([
        'category_id' => $this->category->id,
        'title' => implode('', $chars),
        'price' => '1.00',
        'status' => 1,
    ]);

    $field = $this->writer->buildTitleField($product);

    expect(mb_strlen($field, 'UTF-8'))->toBeLessThanOrEqual(SearchIndexWriter::TITLE_MAX_LENGTH)
        // 结尾必须是完整 token：半个 token 会让该词永远搜不到
        ->and($field)->toMatch('/[\x{4E00}-\x{9FFF}]{2}$/u')
        ->and($field)->not->toEndWith(' ');

    // 截断点附近确实停在上限内，而不是只收了寥寥几个词
    expect(count(explode(' ', $field)))->toBeGreaterThan(100);
});
