<?php

use App\Exceptions\BusinessException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Product\ProductAttributeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-009：SKU 笛卡尔积生成与差异合并（单元测试）
 */
function makeAttribute(string $name, string $type = 'spec', array $values = []): Attribute
{
    $attribute = Attribute::create([
        'name' => $name, 'type' => $type, 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0,
    ]);
    foreach ($values as $i => $value) {
        AttributeValue::create(['attribute_id' => $attribute->id, 'value' => $value, 'sort' => $i]);
    }

    return $attribute->load('values');
}

function makeProductWithCategory(): Product
{
    return Product::create([
        'category_id' => createTestCategory(),
        'title' => '矩阵测试商品'.uniqid(),
        'price' => '99.00',
        'status' => 1,
    ]);
}

test('两维度 3×4 生成 12 个组合', function () {
    $color = makeAttribute('颜色', values: ['黑', '白', '蓝']);
    $size = makeAttribute('尺码', values: ['S', 'M', 'L', 'XL']);

    $matrix = app(ProductAttributeService::class)->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => $color->values->pluck('id')->all()],
        ['attribute_id' => $size->id, 'values' => $size->values->pluck('id')->all()],
    ]);

    expect($matrix)->toHaveCount(12)
        ->and($matrix[0]['specs'])->toHaveKeys(['颜色', '尺码']);
});

test('三维度 2×2×2 生成 8 个组合', function () {
    $a = makeAttribute('颜色', values: ['黑', '白']);
    $b = makeAttribute('尺码', values: ['S', 'M']);
    $c = makeAttribute('版本', values: ['标准版', '高配版']);

    $matrix = app(ProductAttributeService::class)->generateSkuMatrix([
        ['attribute_id' => $a->id, 'values' => $a->values->pluck('id')->all()],
        ['attribute_id' => $b->id, 'values' => $b->values->pluck('id')->all()],
        ['attribute_id' => $c->id, 'values' => $c->values->pluck('id')->all()],
    ]);

    expect($matrix)->toHaveCount(8);

    $signatures = array_column($matrix, 'signature');
    expect(array_unique($signatures))->toHaveCount(8);
});

test('签名与勾选顺序无关（幂等）', function () {
    $color = makeAttribute('颜色', values: ['黑', '白']);
    $size = makeAttribute('尺码', values: ['S', 'M']);

    $service = app(ProductAttributeService::class);
    $m1 = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => [$color->values[0]->id, $color->values[1]->id]],
        ['attribute_id' => $size->id, 'values' => [$size->values[1]->id, $size->values[0]->id]],
    ]);
    $m2 = $service->generateSkuMatrix([
        ['attribute_id' => $size->id, 'values' => [$size->values[0]->id, $size->values[1]->id]],
        ['attribute_id' => $color->id, 'values' => [$color->values[1]->id, $color->values[0]->id]],
    ]);

    sort($m1);
    sort($m2);

    expect(array_column($m1, 'signature'))->toBe(array_column($m2, 'signature'));
});

test('组合数超阈值被拒绝', function () {
    // 人为把上限降到 5
    app(\App\Services\Common\ConfigService::class)->set('product.max_skus', '5');

    $color = makeAttribute('颜色', values: ['黑', '白', '蓝', '红', '灰', '金']);
    $size = makeAttribute('尺码', values: ['S', 'M', 'L']);

    expect(fn () => app(ProductAttributeService::class)->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => $color->values->pluck('id')->all()],
        ['attribute_id' => $size->id, 'values' => $size->values->pluck('id')->all()],
    ]))->toThrow(BusinessException::class);
});

test('合并时已存在 SKU 的价格库存编码保持不变', function () {
    $color = makeAttribute('颜色', values: ['黑', '白']);
    $size = makeAttribute('尺码', values: ['S', 'M']);

    $product = makeProductWithCategory();
    $service = app(ProductAttributeService::class);

    $matrix = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => $color->values->pluck('id')->all()],
        ['attribute_id' => $size->id, 'values' => $size->values->pluck('id')->all()],
    ]);

    $service->mergeSkuMatrix($product, $matrix, array_map(
        fn ($m) => $m + ['price' => '88.00', 'stock' => 7],
        $matrix,
    ));

    expect(ProductSku::where('product_id', $product->id)->count())->toBe(4);

    // 第二次仅改动其中一个组合的价格，其余行必须保持原值
    $first = ProductSku::where('product_id', $product->id)->orderBy('id')->first();
    $service->mergeSkuMatrix($product, $matrix, [
        ['signature' => $first->specs ? ProductAttributeService::signature($first->specs) : '', 'price' => '188.00'],
    ]);

    $first->refresh();
    expect($first->price)->toBe('188.00')
        ->and((int) Inventory::where('sku_id', $first->id)->value('stock'))->toBe(7);

    $others = ProductSku::where('product_id', $product->id)->where('id', '!=', $first->id)->get();
    foreach ($others as $sku) {
        expect((string) $sku->price)->toBe('88.00');
    }

    expect(ProductSku::where('product_id', $product->id)->count())->toBe(4);
});

test('新增与减少维度值：识别新增行与消失行', function () {
    $color = makeAttribute('颜色', values: ['黑', '白']);
    $product = makeProductWithCategory();
    $service = app(ProductAttributeService::class);

    $matrix1 = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => $color->values->pluck('id')->all()],
    ]);
    $service->mergeSkuMatrix($product, $matrix1, array_map(fn ($m) => $m + ['price' => '10.00', 'stock' => 3], $matrix1));
    expect(ProductSku::where('product_id', $product->id)->count())->toBe(2);

    // 增加一个值
    $blue = AttributeValue::create(['attribute_id' => $color->id, 'value' => '蓝', 'sort' => 3]);
    $matrix2 = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => [...$color->values->pluck('id')->all(), $blue->id]],
    ]);
    $result = $service->mergeSkuMatrix($product, $matrix2);
    expect($result['created'])->toBe(1)->and($result['kept'])->toBe(2)
        ->and(ProductSku::where('product_id', $product->id)->count())->toBe(3);

    // 减少到只剩一个值
    $matrix3 = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => [$color->values[0]->id]],
    ]);
    $result = $service->mergeSkuMatrix($product, $matrix3);
    expect($result['deleted'])->toBe(2)->and($result['kept'])->toBe(1)
        ->and(ProductSku::where('product_id', $product->id)->count())->toBe(1);
});

test('被订单引用的 SKU 不被删除而是禁用', function () {
    $color = makeAttribute('颜色', values: ['黑', '白']);
    $product = makeProductWithCategory();
    $service = app(ProductAttributeService::class);

    $matrix = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => $color->values->pluck('id')->all()],
    ]);
    $service->mergeSkuMatrix($product, $matrix, array_map(fn ($m) => $m + ['price' => '10.00', 'stock' => 5], $matrix));

    $blackSku = ProductSku::where('product_id', $product->id)->orderBy('id')->first();

    // 构造一条引用该 SKU 的订单
    $user = createTestUser('matrixbuyer');
    $order = Order::create([
        'order_no' => 'CS'.uniqid(),
        'user_id' => $user->id,
        'status' => Order::STATUS_PAID,
        'total_amount' => '10.00', 'freight_amount' => '0.00', 'pay_amount' => '10.00',
        'address_snapshot' => ['contact_name' => '张三'],
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'sku_id' => $blackSku->id,
        'product_title' => $product->title, 'price' => '10.00', 'quantity' => 1, 'total_amount' => '10.00',
    ]);

    // 只保留白色
    $whiteId = $color->values->last()->id;
    $matrix2 = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => [$whiteId]],
    ]);
    $result = $service->mergeSkuMatrix($product, $matrix2);

    expect($result['disabled'])->toBe(1)->and($result['deleted'])->toBe(0);

    // SKU 行仍在（软删除未触发），但状态为禁用且库存清零
    $still = ProductSku::withTrashed()->find($blackSku->id);
    expect($still)->not->toBeNull()
        ->and($still->trashed())->toBeFalse()
        ->and((int) $still->status)->toBe(0)
        ->and((int) Inventory::where('sku_id', $blackSku->id)->value('stock'))->toBe(0);
});

test('批量按维度改价只影响匹配的 SKU', function () {
    $color = makeAttribute('颜色', values: ['黑', '白']);
    $size = makeAttribute('尺码', values: ['M', 'L']);

    $product = makeProductWithCategory();
    $service = app(ProductAttributeService::class);

    $matrix = $service->generateSkuMatrix([
        ['attribute_id' => $color->id, 'values' => $color->values->pluck('id')->all()],
        ['attribute_id' => $size->id, 'values' => $size->values->pluck('id')->all()],
    ]);
    $service->mergeSkuMatrix($product, $matrix, array_map(fn ($m) => $m + ['price' => '100.00', 'stock' => 1], $matrix));

    $affected = $service->batchSet($product, ['attribute' => '尺码', 'value' => 'M', 'price_delta' => '5.00']);
    expect($affected)->toBe(2);

    foreach (ProductSku::where('product_id', $product->id)->get() as $sku) {
        $expected = ($sku->specs['尺码'] ?? null) === 'M' ? '105.00' : '100.00';
        expect((string) $sku->price)->toBe($expected);
    }
});

test('维度签名对空规格返回固定值', function () {
    expect(ProductAttributeService::signature([]))->toBe('__default__')
        ->and(ProductAttributeService::signature(['b' => '2', 'a' => '1']))->toBe('a:1|b:2');
});
