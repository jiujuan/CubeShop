<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductSku;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-012：存量 specs 迁移脚本
 */
beforeEach(function () {
    config(['app.debug' => true]);
    $this->categoryId = createTestCategory();
    $this->output = sys_get_temp_dir().'/specs-inventory-test.md';
    @unlink($this->output);
});

function makeLegacyProduct(int $categoryId, array $skuSpecsList): Product
{
    $product = Product::create([
        'category_id' => $categoryId,
        'title' => '存量商品'.uniqid(),
        'price' => '50.00',
        'status' => 1,
    ]);

    foreach ($skuSpecsList as $i => $specs) {
        ProductSku::create([
            'product_id' => $product->id,
            'sku_code' => 'LEGACY-'.uniqid().'-'.$i,
            'specs' => $specs,
            'price' => '50.00',
            'status' => 1,
        ]);
    }

    return $product;
}

test('TC-MIG-001 dry-run 只输出清单不写入属性库', function () {
    makeLegacyProduct($this->categoryId, [
        ['颜色' => '黑', '尺码' => 'M'],
        ['颜色' => '白', '尺码' => 'L'],
    ]);

    $this->artisan('products:migrate-specs', ['--output' => $this->output])->assertExitCode(0);

    expect(Attribute::whereIn('name', ['颜色', '尺码'])->count())->toBe(0);
    expect(file_exists($this->output))->toBeTrue()
        ->and(file_get_contents($this->output))->toContain('维度与候选值')
        ->and(file_get_contents($this->output))->toContain('颜色');
});

test('TC-MIG-002 apply 写入属性与属性值并按分类建立模板', function () {
    makeLegacyProduct($this->categoryId, [
        ['颜色' => '黑', '尺码' => 'M'],
        ['颜色' => '白', '尺码' => 'L'],
        ['颜色' => '黑', '尺码' => 'L'],
    ]);

    $this->artisan('products:migrate-specs', ['--apply' => true, '--output' => $this->output])->assertExitCode(0);

    $color = Attribute::where('name', '颜色')->first();
    expect($color)->not->toBeNull()
        ->and($color->type)->toBe('spec')
        ->and($color->is_filterable)->toBeFalse();

    expect(AttributeValue::where('attribute_id', $color->id)->pluck('value')->sort()->values()->all())
        ->toBe(['白', '黑']);

    // 分类模板关联已建立
    expect(CategoryAttribute::where('category_id', $this->categoryId)->where('attribute_id', $color->id)->exists())->toBeTrue();

    // 商品数据未被修改
    expect(Product::where('category_id', $this->categoryId)->count())->toBe(1);
});

test('TC-MIG-003 重复执行幂等（第二次不新建数据）', function () {
    makeLegacyProduct($this->categoryId, [['颜色' => '黑']]);

    $this->artisan('products:migrate-specs', ['--apply' => true, '--output' => $this->output])->assertExitCode(0);
    $attrCount = Attribute::count();
    $valueCount = AttributeValue::count();
    $templateCount = CategoryAttribute::count();

    $this->artisan('products:migrate-specs', ['--apply' => true, '--output' => $this->output])
        ->expectsOutputToContain('新建属性 0 个')
        ->assertExitCode(0);

    expect(Attribute::count())->toBe($attrCount)
        ->and(AttributeValue::count())->toBe($valueCount)
        ->and(CategoryAttribute::count())->toBe($templateCount);
});

test('TC-MIG-004 清单识别疑似脏数据', function () {
    makeLegacyProduct($this->categoryId, [
        ['颜色' => ''],
        ['颜色' => 'CS-2026-0001'],
        ['颜色' => str_repeat('超长', 20)],
        ['颜色' => '带|竖线'],
    ]);

    $this->artisan('products:migrate-specs', ['--output' => $this->output])->assertExitCode(0);

    $content = file_get_contents($this->output);
    expect($content)->toContain('空值')
        ->and($content)->toContain('疑似 SKU 编码被误填为规格值')
        ->and($content)->toContain('超长')
        ->and($content)->toContain('含特殊字符');
});

test('TC-MIG-005 无规格数据时安全退出', function () {
    makeLegacyProduct($this->categoryId, [[]]);

    $this->artisan('products:migrate-specs', ['--output' => $this->output])
        ->expectsOutputToContain('未发现任何规格数据')
        ->assertExitCode(0);
});

test('TC-MIG-006 相同维度值大小写不一致被标记', function () {
    makeLegacyProduct($this->categoryId, [
        ['颜色' => 'Red'],
        ['颜色' => 'red'],
    ]);

    $this->artisan('products:migrate-specs', ['--output' => $this->output])->assertExitCode(0);

    expect(file_get_contents($this->output))->toContain('大小写');
});
