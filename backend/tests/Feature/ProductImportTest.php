<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Common\CaptchaService;
use App\Support\ImportRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * 商品批量导入（xlsx）
 *
 * 覆盖：create 建商品/SKU/库存与分组、自动生成 SKU 编码、表头校验、行数上限、
 *       分类解析（不存在/重名）、编码占用（含软删）、规格重复、外链图片拒绝、
 *       预校验失败零落库、update 改价改库存与流水、权限 403、模板下载。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 造 xlsx 上传文件（$rows 为不含表头的数据行） */
function pimpExcel(array $rows, ?array $header = null): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($header ?? ImportRules::PRODUCT_HEADERS, null, 'A1');
    $sheet->fromArray($rows, null, 'A2');
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $path = tempnam(sys_get_temp_dir(), 'pimp').'.xlsx';
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** 建分类（可指定父级） */
function pimpCategory(string $name, int $parentId = 0): Category
{
    return Category::create(['parent_id' => $parentId, 'name' => $name, 'sort' => 0, 'status' => 1]);
}

/** 建品牌 */
function pimpBrand(string $name): Brand
{
    return Brand::create(['name' => $name, 'sort' => 0, 'status' => 1]);
}

/** create 模式的一行样例（15 列，按数字索引覆盖） */
function pimpRow(array $overrides = []): array
{
    $row = [
        'P001',
        '测试商品',
        '副标题',
        '分类A',
        '品牌A',
        '',
        '',
        '上架',
        '200',
        '0',
        'SKU-1',
        '颜色:红色',
        '99.00',
        '10',
        '启用',
    ];

    foreach ($overrides as $index => $value) {
        $row[$index] = $value;
    }

    return $row;
}

/** 提交导入 */
function pimpImport($test, array $rows, string $mode = 'create', ?array $header = null)
{
    return $test->postJson('/api/admin/products/import', [
        'file' => pimpExcel($rows, $header),
        'mode' => $mode,
    ], $test->adminAuth);
}

test('create 模式：同一商品编码的多行合并为一个商品并各自建 SKU 与库存', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    $res = pimpImport($this, [
        pimpRow(),
        pimpRow([10 => 'SKU-2', 11 => '颜色:黑色', 12 => '109.00', 13 => '20']),
    ]);

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.products'))->toBe(1)
        ->and($res->json('data.success'))->toBe(2);

    $product = Product::first();
    expect($product->code)->toBe('P001')
        ->and($product->title)->toBe('测试商品')
        ->and($product->status)->toBe(1)
        // 展示价取最低有效价
        ->and((string) $product->price)->toBe('99.00');

    $skus = $product->skus()->orderBy('id')->get();
    expect($skus)->toHaveCount(2)
        ->and($skus[0]->sku_code)->toBe('SKU-1')
        ->and($skus[0]->specs)->toBe(['颜色' => '红色'])
        ->and($skus[0]->inventory->stock)->toBe(10)
        ->and($skus[1]->sku_code)->toBe('SKU-2')
        ->and($skus[1]->inventory->stock)->toBe(20);
});

test('create 模式：SKU 编码留空时自动生成', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    $res = pimpImport($this, [pimpRow([10 => ''])]);

    expect($res->json('code'))->toBe(0);

    $sku = ProductSku::first();
    expect($sku->sku_code)->toMatch('/^CS-\d+-1$/');
});

test('表头不符合模板时整批拒绝', function () {
    $res = pimpImport($this, [pimpRow()], 'create', ['商品编码', '商品标题']);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('message'))->toContain('表头不符合模板')
        ->and(Product::count())->toBe(0);
});

test('create 模式超过 300 行被拒绝', function () {
    $rows = [];
    for ($i = 1; $i <= ImportRules::CREATE_MAX_ROWS + 1; $i++) {
        $rows[] = pimpRow([0 => 'P'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 10 => 'SKU-'.$i]);
    }

    $res = pimpImport($this, $rows);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('message'))->toContain('单次最多 '.ImportRules::CREATE_MAX_ROWS.' 行');
});

test('分类不存在时该行失败', function () {
    $res = pimpImport($this, [pimpRow([3 => '不存在的分类'])]);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('不存在')
        ->and(Product::count())->toBe(0);
});

test('分类名称重名时要求改填 ID', function () {
    pimpCategory('分类A');
    pimpCategory('分类A'); // 同名
    pimpBrand('品牌A');

    $res = pimpImport($this, [pimpRow()]);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('多个同名分类');

    // 改填分类 ID 后可通过
    $id = Category::first()->id;
    $res = pimpImport($this, [pimpRow([3 => (string) $id])]);
    expect($res->json('code'))->toBe(0);
});

test('商品编码已存在时拒绝（提示改用更新模式）', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');
    Product::create(['code' => 'P001', 'title' => '已有商品', 'status' => 1, 'price' => '10.00']);

    $res = pimpImport($this, [pimpRow()]);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('已存在');
});

test('SKU 编码被软删记录占用时拒绝', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    $product = Product::create(['title' => '历史商品', 'status' => 1, 'price' => '10.00']);
    $old = ProductSku::create(['product_id' => $product->id, 'sku_code' => 'SKU-1', 'price' => '1.00', 'status' => 1]);
    $old->delete();

    $res = pimpImport($this, [pimpRow()]);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('已被占用');
});

test('同一商品内规格重复时拒绝', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    $res = pimpImport($this, [
        pimpRow(),
        pimpRow([10 => 'SKU-2', 11 => '颜色:红色']),
    ]);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('规格');
});

test('主图填外链地址时拒绝（V1 不支持自动下载）', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    $res = pimpImport($this, [pimpRow([5 => 'https://example.com/a.jpg'])]);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('外链图片');
});

test('预校验失败时一个商品都不落库', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    // 第一行合法、第二行价格非法
    pimpImport($this, [
        pimpRow(),
        pimpRow([10 => 'SKU-2', 11 => '颜色:黑色', 12 => 'abc']),
    ]);

    expect(Product::count())->toBe(0)
        ->and(ProductSku::count())->toBe(0);
});

test('update 模式：按 SKU 编码改价改库存并留库存流水', function () {
    $sku = createTestSku(stock: 10, price: '50.00');

    $res = pimpImport($this, [
        ['', '', '', '', '', '', '', '', '', '', $sku->sku_code, '', '88.00', '25', '启用'],
    ], 'update');

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.success'))->toBe(1);

    $sku->refresh();
    expect((string) $sku->price)->toBe('88.00')
        ->and($sku->inventory->stock)->toBe(25);

    $log = InventoryLog::where('biz_type', 'import')->first();
    expect($log)->not->toBeNull()
        ->and($log->sku_id)->toBe($sku->id)
        ->and($log->change_qty)->toBe(15)
        ->and($log->change_type)->toBe('adjust');
});

test('update 模式：SKU 编码不存在 / 未填任何更新列均报失败', function () {
    $res = pimpImport($this, [['', '', '', '', '', '', '', '', '', '', 'NOPE', '', '10.00', '', '']], 'update');
    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('不存在');

    $sku = createTestSku();
    $res = pimpImport($this, [['', '', '', '', '', '', '', '', '', '', $sku->sku_code, '', '', '', '']], 'update');
    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('至少填写一项');
});

test('无 product.import 权限返回 403', function () {
    $user = createTestUser('pimpnoperm');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->postJson('/api/admin/products/import', [
        'file' => pimpExcel([pimpRow()]),
        'mode' => 'create',
    ], $auth)->assertStatus(403);
});

test('模板下载返回 xlsx 且表头与常量一致', function () {
    pimpCategory('分类A');
    pimpBrand('品牌A');

    $res = $this->get('/api/admin/products/import/template?mode=create', $this->adminAuth);

    expect($res->getStatusCode())->toBe(200)
        ->and($res->headers->get('content-disposition'))->toContain('xlsx');

    $path = tempnam(sys_get_temp_dir(), 'pimptpl').'.xlsx';
    file_put_contents($path, $res->streamedContent());

    $spreadsheet = IOFactory::load($path);
    $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
    expect($rows[0])->toBe(ImportRules::PRODUCT_HEADERS)
        ->and($spreadsheet->getSheetNames())->toContain('填写说明')
        ->and($spreadsheet->getSheetNames())->toContain('分类对照')
        ->and($spreadsheet->getSheetNames())->toContain('品牌对照');
});
