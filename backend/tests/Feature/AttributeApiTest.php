<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSku;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-007 / T-008 / T-009：属性体系管理接口、分类模板、SKU 矩阵
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $cap2 = app(CaptchaService::class)->generate();
    $this->userAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'attrbuyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];

    $this->categoryId = createTestCategory();
});

/** 用与服务一致的口径生成维度签名（避免测试里手写顺序导致不匹配） */
function sig(array $specs): string
{
    return \App\Services\Product\ProductAttributeService::signature($specs);
}

// ---------- 品牌 ----------

test('TC-ATTR-001 品牌增删改查', function () {
    $created = $this->postJson('/api/admin/brands', ['name' => '测试品牌'.uniqid(), 'sort' => 10], $this->adminAuth)->json();
    expect($created['code'])->toBe(0);
    $id = $created['data']['id'];

    $list = $this->getJson('/api/admin/brands', $this->adminAuth)->json();
    expect($list['code'])->toBe(0)->and($list['data']['pagination']['total'])->toBeGreaterThanOrEqual(1);

    $updated = $this->putJson("/api/admin/brands/{$id}", ['name' => '改名品牌'.uniqid()], $this->adminAuth)->json();
    expect($updated['code'])->toBe(0);

    $deleted = $this->deleteJson("/api/admin/brands/{$id}", [], $this->adminAuth)->json();
    expect($deleted['code'])->toBe(0);

    expect(Brand::find($id))->toBeNull();
});

test('TC-ATTR-002 被商品引用的品牌不可删除', function () {
    $brand = Brand::create(['name' => '被引用品牌'.uniqid(), 'sort' => 0, 'status' => 1]);
    Product::create([
        'category_id' => $this->categoryId, 'title' => '品牌商品', 'price' => '10.00',
        'status' => 1, 'brand_id' => $brand->id,
    ]);

    $resp = $this->deleteJson("/api/admin/brands/{$brand->id}", [], $this->adminAuth)->json();

    expect($resp['code'])->toBe(40009)
        ->and($resp['message'])->toContain('引用');
});

// ---------- 属性 ----------

test('TC-ATTR-003 属性与属性值 CRUD', function () {
    $created = $this->postJson('/api/admin/attributes', [
        'name' => '测试属性'.uniqid(),
        'type' => 'spec',
        'is_filterable' => true,
        'values' => ['A', 'B'],
    ], $this->adminAuth)->json();
    expect($created['code'])->toBe(0);
    $attributeId = $created['data']['id'];

    $detail = $this->getJson("/api/admin/attributes/{$attributeId}", $this->adminAuth)->json('data');
    expect($detail['values'])->toHaveCount(2)
        ->and($detail['is_filterable'])->toBeTrue();

    // 追加属性值
    $added = $this->postJson("/api/admin/attributes/{$attributeId}/values", ['value' => 'C'], $this->adminAuth)->json();
    expect($added['code'])->toBe(0);

    // 重复值被拒
    $dup = $this->postJson("/api/admin/attributes/{$attributeId}/values", ['value' => 'C'], $this->adminAuth)->json();
    expect($dup['code'])->toBe(40009);

    // 批量覆盖保存（移除未使用的值）
    $batch = $this->postJson("/api/admin/attributes/{$attributeId}/values/batch", [
        'values' => ['A', 'D'],
    ], $this->adminAuth)->json();
    expect($batch['code'])->toBe(0)
        ->and($batch['data']['created'])->toBe(1)
        ->and($batch['data']['removed'])->toBe(2);

    expect(AttributeValue::where('attribute_id', $attributeId)->pluck('value')->sort()->values()->all())
        ->toBe(['A', 'D']);
});

test('TC-ATTR-004 属性值删除时被引用则拒绝', function () {
    $attribute = Attribute::create(['name' => '材质'.uniqid(), 'type' => 'param', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $value = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => '金属', 'sort' => 0]);

    $product = Product::create(['category_id' => $this->categoryId, 'title' => '参数商品', 'price' => '9.00', 'status' => 1]);
    \App\Models\ProductAttributeValue::create(['product_id' => $product->id, 'attribute_id' => $attribute->id, 'value' => '金属']);

    $resp = $this->deleteJson("/api/admin/attributes/{$attribute->id}/values/{$value->id}", [], $this->adminAuth)->json();
    expect($resp['code'])->toBe(40009);
});

// ---------- 分类模板 ----------

test('TC-ATTR-005 分类属性模板保存与读取（覆盖语义）', function () {
    $color = Attribute::create(['name' => '颜色'.uniqid(), 'type' => 'spec', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $material = Attribute::create(['name' => '材质'.uniqid(), 'type' => 'param', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $power = Attribute::create(['name' => '功率'.uniqid(), 'type' => 'param', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);

    // 首次保存 3 个
    $save = $this->putJson("/api/admin/categories/{$this->categoryId}/attributes", [
        'attributes' => [
            ['attribute_id' => $color->id, 'is_required' => true, 'sort' => 30],
            ['attribute_id' => $material->id, 'is_required' => false, 'sort' => 20],
            ['attribute_id' => $power->id, 'is_required' => false, 'sort' => 10],
        ],
    ], $this->adminAuth)->json();
    expect($save['code'])->toBe(0);

    $read = $this->getJson("/api/admin/categories/{$this->categoryId}/attributes", $this->adminAuth)->json('data');
    expect($read['attributes'])->toHaveCount(3);

    // 覆盖保存：移除 power，保留两个
    $this->putJson("/api/admin/categories/{$this->categoryId}/attributes", [
        'attributes' => [
            ['attribute_id' => $color->id, 'is_required' => true, 'sort' => 30],
            ['attribute_id' => $material->id, 'is_required' => false, 'sort' => 20],
        ],
    ], $this->adminAuth)->assertStatus(200);

    $read2 = $this->getJson("/api/admin/categories/{$this->categoryId}/attributes", $this->adminAuth)->json('data');
    expect($read2['attributes'])->toHaveCount(2);
});

test('TC-ATTR-006 模板保存校验属性存在性', function () {
    $resp = $this->putJson("/api/admin/categories/{$this->categoryId}/attributes", [
        'attributes' => [['attribute_id' => 999999]],
    ], $this->adminAuth)->json();

    expect($resp['code'])->toBe(40000);
});

// ---------- 权限 ----------

test('TC-ATTR-007 无权限用户访问属性管理被拒 403', function () {
    $this->getJson('/api/admin/brands', $this->userAuth)->assertStatus(403);
    $this->postJson('/api/admin/attributes', ['name' => 'x', 'type' => 'spec'], $this->userAuth)->assertStatus(403);
});

// ---------- 前台只读 ----------

test('TC-ATTR-008 前台属性与品牌接口匿名可访问', function () {
    $color = Attribute::create(['name' => '颜色前台'.uniqid(), 'type' => 'spec', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    AttributeValue::create(['attribute_id' => $color->id, 'value' => '红', 'sort' => 0]);
    \App\Models\CategoryAttribute::create(['category_id' => $this->categoryId, 'attribute_id' => $color->id, 'is_required' => false, 'sort' => 0]);

    Brand::create(['name' => '在售品牌'.uniqid(), 'sort' => 0, 'status' => 1]);
    Brand::create(['name' => '停用品牌'.uniqid(), 'sort' => 0, 'status' => 0]);

    $attrs = $this->getJson('/api/attributes?category_id='.$this->categoryId.'&filterable=1')->json();
    expect($attrs['code'])->toBe(0)->and($attrs['data'])->toHaveCount(1)
        ->and($attrs['data'][0]['values'])->toHaveCount(1);

    $brands = $this->getJson('/api/brands')->json();
    expect($brands['code'])->toBe(0);
    $names = array_column($brands['data'], 'name');
    expect(collect($names)->filter(fn ($n) => str_starts_with($n, '停用品牌')))->toHaveCount(0);
});

test('TC-ATTR-009 未配置模板的分类返回空属性列表', function () {
    $resp = $this->getJson('/api/attributes?category_id='.$this->categoryId)->json();
    expect($resp['code'])->toBe(0)->and($resp['data'])->toBe([]);
});

// ---------- 商品参数校验 ----------

test('TC-ATTR-010 商品保存时校验参数属于分类模板', function () {
    $color = Attribute::create(['name' => '颜色校验'.uniqid(), 'type' => 'param', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    AttributeValue::create(['attribute_id' => $color->id, 'value' => '蓝', 'sort' => 0]);
    $other = Attribute::create(['name' => '模板外属性'.uniqid(), 'type' => 'param', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    AttributeValue::create(['attribute_id' => $other->id, 'value' => 'X', 'sort' => 0]);
    \App\Models\CategoryAttribute::create(['category_id' => $this->categoryId, 'attribute_id' => $color->id, 'is_required' => true, 'sort' => 0]);

    // 缺必填属性
    $r1 = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '缺参数商品',
        'skus' => [['specs' => ['规格' => '标准'], 'price' => '10.00', 'stock' => 1]],
    ], $this->adminAuth)->json();
    expect($r1['code'])->toBe(40000)->and($r1['message'])->toContain('必填');

    // 非模板属性
    $r2 = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '越界参数商品',
        'attribute_values' => [['attribute_id' => $other->id, 'value' => 'X']],
        'skus' => [['specs' => ['规格' => '标准'], 'price' => '10.00', 'stock' => 1]],
    ], $this->adminAuth)->json();
    expect($r2['code'])->toBe(40000)->and($r2['message'])->toContain('模板');

    // 非法值
    $r3 = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '非法值商品',
        'attribute_values' => [['attribute_id' => $color->id, 'value' => '不存在']],
        'skus' => [['specs' => ['规格' => '标准'], 'price' => '10.00', 'stock' => 1]],
    ], $this->adminAuth)->json();
    expect($r3['code'])->toBe(40000)->and($r3['message'])->toContain('不合法');

    // 合法提交
    $ok = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '合法参数商品',
        'attribute_values' => [['attribute_id' => $color->id, 'value' => '蓝']],
        'skus' => [['specs' => ['规格' => '标准'], 'price' => '10.00', 'stock' => 1]],
    ], $this->adminAuth)->json();
    expect($ok['code'])->toBe(0);
});

// ---------- SKU 矩阵接口 ----------

test('TC-ATTR-011 使用 specs_selection 创建商品自动生成 SKU 矩阵', function () {
    $color = Attribute::create(['name' => '颜色矩阵'.uniqid(), 'type' => 'spec', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $c1 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '黑', 'sort' => 0]);
    $c2 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '白', 'sort' => 0]);

    $size = Attribute::create(['name' => '尺码矩阵'.uniqid(), 'type' => 'spec', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $s1 = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'S', 'sort' => 0]);
    $s2 = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'M', 'sort' => 0]);

    $resp = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId,
        'title' => '矩阵商品',
        'specs_selection' => [
            ['attribute_id' => $color->id, 'values' => [$c1->id, $c2->id]],
            ['attribute_id' => $size->id, 'values' => [$s1->id, $s2->id]],
        ],
        'skus' => [
            ['signature' => sig([$color->name => '黑', $size->name => 'S']), 'price' => '100.00', 'stock' => 3],
            ['signature' => sig([$color->name => '黑', $size->name => 'M']), 'price' => '110.00', 'stock' => 4],
            ['signature' => sig([$color->name => '白', $size->name => 'S']), 'price' => '120.00', 'stock' => 5],
            ['signature' => sig([$color->name => '白', $size->name => 'M']), 'price' => '130.00', 'stock' => 6],
        ],
    ], $this->adminAuth)->json();

    expect($resp['code'])->toBe(0);
    $productId = $resp['data']['id'];

    $skus = ProductSku::where('product_id', $productId)->get();
    expect($skus)->toHaveCount(4);

    // 商品展示价 = 最低有效 SKU 价
    expect((string) Product::find($productId)->price)->toBe('100.00');

    $blackS = $skus->first(fn ($s) => $s->specs[$color->name] === '黑' && $s->specs[$size->name] === 'S');
    expect($blackS)->not->toBeNull()
        ->and((int) Inventory::where('sku_id', $blackS->id)->value('stock'))->toBe(3);
});

test('TC-ATTR-012 SKU 矩阵预览返回新增/保留/移除明细', function () {
    $color = Attribute::create(['name' => '颜色预览'.uniqid(), 'type' => 'spec', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $c1 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '黑', 'sort' => 0]);
    $c2 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '白', 'sort' => 0]);

    $create = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '预览商品',
        'specs_selection' => [['attribute_id' => $color->id, 'values' => [$c1->id, $c2->id]]],
        'skus' => [
            ['signature' => sig([$color->name => '黑']), 'price' => '10.00', 'stock' => 1],
            ['signature' => sig([$color->name => '白']), 'price' => '20.00', 'stock' => 2],
        ],
    ], $this->adminAuth)->json();
    $productId = $create['data']['id'];

    $preview = $this->postJson('/api/admin/products/sku-matrix', [
        'product_id' => $productId,
        'specs_selection' => [['attribute_id' => $color->id, 'values' => [$c1->id]]],
    ], $this->adminAuth)->json('data');

    expect($preview['total'])->toBe(1)
        ->and($preview['kept'])->toHaveCount(1)
        ->and($preview['removed'])->toHaveCount(1)
        ->and($preview['removed'][0]['action'])->toBe('delete');
});

test('TC-ATTR-013 编辑商品仅更新变化行，保留其余 SKU 价格库存', function () {
    $color = Attribute::create(['name' => '颜色编辑'.uniqid(), 'type' => 'spec', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $c1 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '黑', 'sort' => 0]);
    $c2 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '白', 'sort' => 0]);

    $create = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '编辑矩阵商品',
        'specs_selection' => [['attribute_id' => $color->id, 'values' => [$c1->id, $c2->id]]],
        'skus' => [
            ['signature' => sig([$color->name => '黑']), 'price' => '10.00', 'stock' => 7],
            ['signature' => sig([$color->name => '白']), 'price' => '20.00', 'stock' => 8],
        ],
    ], $this->adminAuth)->json();
    $productId = $create['data']['id'];

    // 只改黑色价格
    $update = $this->putJson("/api/admin/products/{$productId}", [
        'specs_selection' => [['attribute_id' => $color->id, 'values' => [$c1->id, $c2->id]]],
        'skus' => [['signature' => sig([$color->name => '黑']), 'price' => '99.00']],
    ], $this->adminAuth)->json();
    expect($update['code'])->toBe(0);

    $skus = ProductSku::where('product_id', $productId)->get();
    expect($skus)->toHaveCount(2);

    $black = $skus->first(fn ($s) => $s->specs[$color->name] === '黑');
    $white = $skus->first(fn ($s) => $s->specs[$color->name] === '白');

    expect($black)->not->toBeNull()
        ->and($white)->not->toBeNull()
        ->and((string) $black->price)->toBe('99.00')
        ->and((int) Inventory::where('sku_id', $black->id)->value('stock'))->toBe(7)
        ->and((string) $white->price)->toBe('20.00')
        ->and((int) Inventory::where('sku_id', $white->id)->value('stock'))->toBe(8);
});

test('TC-ATTR-014 按维度批量设置 SKU', function () {
    $color = Attribute::create(['name' => '颜色批量'.uniqid(), 'type' => 'spec', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    $c1 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '黑', 'sort' => 0]);
    $c2 = AttributeValue::create(['attribute_id' => $color->id, 'value' => '白', 'sort' => 0]);

    $create = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId, 'title' => '批量商品',
        'specs_selection' => [['attribute_id' => $color->id, 'values' => [$c1->id, $c2->id]]],
        'skus' => [
            ['signature' => sig([$color->name => '黑']), 'price' => '10.00', 'stock' => 1],
            ['signature' => sig([$color->name => '白']), 'price' => '20.00', 'stock' => 1],
        ],
    ], $this->adminAuth)->json();
    $productId = $create['data']['id'];

    $resp = $this->postJson("/api/admin/products/{$productId}/skus/batch-set", [
        'attribute' => $color->name, 'value' => '黑', 'price_delta' => '5.00', 'stock' => 30,
    ], $this->adminAuth)->json();

    expect($resp['code'])->toBe(0)->and($resp['data']['affected'])->toBe(1);

    $black = ProductSku::where('product_id', $productId)->get()->first(fn ($s) => $s->specs[$color->name] === '黑');
    expect((string) $black->price)->toBe('15.00')
        ->and((int) Inventory::where('sku_id', $black->id)->value('stock'))->toBe(30);
});

test('TC-ATTR-015 旧结构 skus[].specs 创建商品仍可用（向后兼容）', function () {
    $resp = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId,
        'title' => '旧结构商品',
        'skus' => [
            ['sku_code' => 'LEGACY-'.uniqid(), 'specs' => ['规格' => '标准'], 'price' => '55.00', 'stock' => 2],
        ],
    ], $this->adminAuth)->json();

    expect($resp['code'])->toBe(0);
    expect(ProductSku::where('product_id', $resp['data']['id'])->count())->toBe(1);
});

// ---------- 旧结构（历史/导入数据）SKU 差异合并 ----------

/** 造一个「旧结构」商品：SKU 直接带 specs，规格值不在属性值库中（模拟历史导入数据） */
function makeImportedProduct(int $categoryId, array $rows): Product
{
    $product = Product::create([
        'category_id' => $categoryId,
        'title' => '历史导入商品'.uniqid(),
        'price' => '69.00',
        'status' => 1,
    ]);

    foreach ($rows as $row) {
        $sku = ProductSku::create([
            'product_id' => $product->id,
            'sku_code' => $row['sku_code'],
            'specs' => $row['specs'],
            'price' => $row['price'],
            'status' => $row['status'] ?? 1,
        ]);

        Inventory::create(['sku_id' => $sku->id, 'stock' => $row['stock']]);
    }

    return $product;
}

test('TC-ATTR-022 旧结构商品改图片/详情保存成功，SKU 原行原地更新不撞唯一键', function () {
    $product = makeImportedProduct($this->categoryId, [
        ['sku_code' => 'LEG-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1200],
        ['sku_code' => 'LEG-02', 'specs' => ['容量' => '600ml'], 'price' => '89.00', 'stock' => 800],
    ]);
    $pid = $product->id;

    $before = ProductSku::where('product_id', $pid)->orderBy('id')->pluck('id')->all();
    expect($before)->toHaveCount(2);

    // 复现报错场景：仅新增详情图后保存（表格沿用原编码原样回传）
    $resp = $this->putJson("/api/admin/products/{$pid}", [
        'title' => '历史导入商品',
        'images' => ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'],
        'skus' => [
            ['sku_code' => 'LEG-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1200, 'status' => 1],
            ['sku_code' => 'LEG-02', 'specs' => ['容量' => '600ml'], 'price' => '89.00', 'stock' => 800, 'status' => 1],
        ],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)->and($resp->status())->toBe(200);

    $after = ProductSku::where('product_id', $pid)->orderBy('id')->get();
    expect($after->pluck('id')->all())->toBe($before)                      // id 不变
        ->and($after->pluck('sku_code')->all())->toBe(['LEG-01', 'LEG-02'])
        ->and($after->firstWhere('sku_code', 'LEG-01')->specs)->toBe(['容量' => '450ml']) // 规格名未被篡改
        ->and(ProductSku::withTrashed()->where('product_id', $pid)->count())->toBe(2)     // 无软删残留
        ->and(ProductImage::where('product_id', $pid)->count())->toBe(2);                 // 图片已保存
});

test('TC-ATTR-023 旧结构更新：本次未提交的 SKU 软删除并清零库存', function () {
    $pid = makeImportedProduct($this->categoryId, [
        ['sku_code' => 'DROP-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 10],
        ['sku_code' => 'DROP-02', 'specs' => ['容量' => '600ml'], 'price' => '89.00', 'stock' => 5],
    ])->id;

    $dropped = ProductSku::where('product_id', $pid)->where('sku_code', 'DROP-02')->firstOrFail();

    $resp = $this->putJson("/api/admin/products/{$pid}", [
        'title' => '历史导入商品',
        'skus' => [
            ['sku_code' => 'DROP-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 10, 'status' => 1],
        ],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(0)
        ->and(ProductSku::where('product_id', $pid)->count())->toBe(1)
        ->and(ProductSku::withTrashed()->find($dropped->id)->trashed())->toBeTrue()
        ->and((int) Inventory::where('sku_id', $dropped->id)->value('stock'))->toBe(0);
});

test('TC-ATTR-024 旧结构更新：提交内编码重复返回业务冲突', function () {
    $pid = makeImportedProduct($this->categoryId, [
        ['sku_code' => 'DUP-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1],
    ])->id;

    $resp = $this->putJson("/api/admin/products/{$pid}", [
        'title' => '历史导入商品',
        'skus' => [
            ['sku_code' => 'DUP-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1],
            ['sku_code' => 'DUP-01', 'specs' => ['容量' => '600ml'], 'price' => '89.00', 'stock' => 1],
        ],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009)->and($resp->status())->toBe(409);
});

test('TC-ATTR-025 旧结构更新：占用他商品的编码返回业务冲突而非 500', function () {
    $other = makeImportedProduct($this->categoryId, [
        ['sku_code' => 'TAKEN-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1],
    ])->id;
    $pid = makeImportedProduct($this->categoryId, [
        ['sku_code' => 'MINE-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1],
    ])->id;

    $resp = $this->putJson("/api/admin/products/{$pid}", [
        'title' => '历史导入商品',
        'skus' => [
            ['sku_code' => 'TAKEN-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1],
        ],
    ], $this->adminAuth);

    expect($resp->json('code'))->toBe(40009)->and($resp->status())->toBe(409)
        ->and(ProductSku::where('product_id', $other)->count())->toBe(1)  // 未破坏他商品数据
        ->and(ProductSku::where('product_id', $pid)->count())->toBe(1);   // 事务回滚
});

test('TC-ATTR-026 旧结构更新：留空编码自动生成且不与软删历史行冲突', function () {
    $pid = makeImportedProduct($this->categoryId, [
        ['sku_code' => 'KEEP-01', 'specs' => ['容量' => '450ml'], 'price' => '69.00', 'stock' => 1],
    ])->id;

    $payload = fn (string $spec) => [
        'title' => '历史导入商品',
        'skus' => [['sku_code' => null, 'specs' => ['容量' => $spec], 'price' => '69.00', 'stock' => 1, 'status' => 1]],
    ];

    // 连续两次改动规格 → 每次都新增一行并软删上一行，编码必须始终唯一
    expect($this->putJson("/api/admin/products/{$pid}", $payload('600ml'), $this->adminAuth)->json('code'))->toBe(0)
        ->and($this->putJson("/api/admin/products/{$pid}", $payload('750ml'), $this->adminAuth)->json('code'))->toBe(0);

    $all = ProductSku::withTrashed()->where('product_id', $pid)->get();
    expect($all)->toHaveCount(3)
        ->and($all->pluck('sku_code')->unique())->toHaveCount(3)
        ->and(ProductSku::where('product_id', $pid)->count())->toBe(1)
        ->and(ProductSku::where('product_id', $pid)->first()->specs)->toBe(['容量' => '750ml']);
});

test('TC-ATTR-016 删除被 SKU 规格引用的属性被拒', function () {
    $attribute = Attribute::create(['name' => '被引用规格'.uniqid(), 'type' => 'spec', 'is_filterable' => false, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);

    $product = Product::create(['category_id' => $this->categoryId, 'title' => '规格商品', 'price' => '10.00', 'status' => 1]);
    ProductSku::create([
        'product_id' => $product->id, 'sku_code' => 'S-'.uniqid(),
        'specs' => [$attribute->name => '任意值'], 'price' => '10.00', 'status' => 1,
    ]);

    $resp = $this->deleteJson("/api/admin/attributes/{$attribute->id}", [], $this->adminAuth)->json();

    expect($resp['code'])->toBe(40009);
});

// ---------- 前台属性筛选（T-014 后端） ----------

/** 建一个带参数属性的在售商品（可选品牌） */
function makeFilterableProduct(int $categoryId, string $attributeName, string $value, ?int $brandId = null, string $title = '筛选商品'): Product
{
    $attribute = Attribute::firstOrCreate(
        ['name' => $attributeName],
        ['type' => 'param', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0],
    );

    $product = Product::create([
        'category_id' => $categoryId, 'title' => $title, 'price' => '20.00', 'status' => 1, 'brand_id' => $brandId,
    ]);
    \App\Models\ProductAttributeValue::create([
        'product_id' => $product->id, 'attribute_id' => $attribute->id, 'value' => $value,
    ]);

    return $product;
}

test('TC-ATTR-017 前台按品牌筛选商品', function () {
    $brandA = Brand::create(['name' => '品牌A'.uniqid(), 'sort' => 0, 'status' => 1]);
    $brandB = Brand::create(['name' => '品牌B'.uniqid(), 'sort' => 0, 'status' => 1]);

    makeFilterableProduct($this->categoryId, '材质'.uniqid(), '金属', $brandA->id, '品牌A商品');
    makeFilterableProduct($this->categoryId, '材质'.uniqid(), '塑料', $brandB->id, '品牌B商品');

    $resp = $this->getJson('/api/products?brand_id='.$brandA->id)->json();
    expect($resp['code'])->toBe(0)
        ->and($resp['data']['list'])->toHaveCount(1)
        ->and($resp['data']['list'][0]['title'])->toBe('品牌A商品');
});

test('TC-ATTR-018 前台按属性筛选（同属性 OR、跨属性 AND）', function () {
    $material = '材质筛选'.uniqid();
    $size = '尺寸筛选'.uniqid();

    // 预先创建尺寸属性，便于给商品挂第二个参数
    $sizeAttribute = Attribute::create([
        'name' => $size, 'type' => 'param', 'is_filterable' => true,
        'is_multiple' => false, 'allow_custom' => false, 'sort' => 0,
    ]);

    $p1 = makeFilterableProduct($this->categoryId, $material, '金属', null, '金属大号');
    \App\Models\ProductAttributeValue::create([
        'product_id' => $p1->id,
        'attribute_id' => $sizeAttribute->id,
        'value' => '大号',
    ]);

    makeFilterableProduct($this->categoryId, $material, '塑料', null, '塑料小号');

    $materialId = Attribute::where('name', $material)->value('id');

    // 单属性单值
    $r1 = $this->getJson('/api/products?attribute_values[]='.$materialId.':金属')->json();
    expect($r1['data']['list'])->toHaveCount(1)
        ->and($r1['data']['list'][0]['title'])->toBe('金属大号');

    // 同属性多值（OR）
    $r2 = $this->getJson('/api/products?attribute_values[]='.$materialId.':金属&attribute_values[]='.$materialId.':塑料')->json();
    expect($r2['data']['list'])->toHaveCount(2);

    // 跨属性 AND：材质=金属 且 尺寸=大号
    $r3 = $this->getJson('/api/products?attribute_values[]='.$materialId.':金属&attribute_values[]='.$sizeAttribute->id.':大号')->json();
    expect($r3['data']['list'])->toHaveCount(1);

    // 跨属性 AND 无交集
    $r4 = $this->getJson('/api/products?attribute_values[]='.$materialId.':塑料&attribute_values[]='.$sizeAttribute->id.':大号')->json();
    expect($r4['data']['list'])->toHaveCount(0);
});

test('TC-ATTR-021 品牌+属性+价格区间叠加筛选与无结果', function () {
    $brand = Brand::create(['name' => '叠加品牌'.uniqid(), 'sort' => 0, 'status' => 1]);
    $material = '叠加材质'.uniqid();

    $cheapAttr = Attribute::firstOrCreate(
        ['name' => $material],
        ['type' => 'param', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0],
    );
    $materialId = $cheapAttr->id;

    // 低价命中商品
    $p1 = Product::create(['category_id' => $this->categoryId, 'title' => '命中商品', 'price' => '50.00', 'status' => 1, 'brand_id' => $brand->id]);
    \App\Models\ProductAttributeValue::create(['product_id' => $p1->id, 'attribute_id' => $materialId, 'value' => '金属']);

    // 同品牌同属性但超价区间
    $p2 = Product::create(['category_id' => $this->categoryId, 'title' => '超价商品', 'price' => '500.00', 'status' => 1, 'brand_id' => $brand->id]);
    \App\Models\ProductAttributeValue::create(['product_id' => $p2->id, 'attribute_id' => $materialId, 'value' => '金属']);

    // 三个条件叠加（品牌 + 属性 + 价格 1~100）
    $resp = $this->getJson('/api/products?brand_id='.$brand->id.'&attribute_values[]='.$materialId.':金属&min_price=1&max_price=100')->json();
    expect($resp['code'])->toBe(0)
        ->and($resp['data']['list'])->toHaveCount(1)
        ->and($resp['data']['list'][0]['title'])->toBe('命中商品');

    // 无结果：属性值不存在
    $none = $this->getJson('/api/products?attribute_values[]='.$materialId.':不存在的值')->json();
    expect($none['data']['list'])->toHaveCount(0)
        ->and($none['data']['pagination']['total'])->toBe(0);
});

test('TC-ATTR-019 商品详情返回品牌与商品参数', function () {
    $brand = Brand::create(['name' => '详情品牌'.uniqid(), 'sort' => 0, 'status' => 1]);
    $attributeName = '材质详情'.uniqid();

    $attribute = Attribute::create(['name' => $attributeName, 'type' => 'param', 'is_filterable' => true, 'is_multiple' => false, 'allow_custom' => false, 'sort' => 0]);
    AttributeValue::create(['attribute_id' => $attribute->id, 'value' => '铝合金', 'sort' => 0]);
    \App\Models\CategoryAttribute::create(['category_id' => $this->categoryId, 'attribute_id' => $attribute->id, 'is_required' => false, 'sort' => 0]);

    $create = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId,
        'title' => '带参数商品',
        'brand_id' => $brand->id,
        'weight' => 350,
        'status' => 1,
        'attribute_values' => [['attribute_id' => $attribute->id, 'value' => '铝合金']],
        'skus' => [['specs' => ['规格' => '标准'], 'price' => '88.00', 'stock' => 2]],
    ], $this->adminAuth)->json();
    expect($create['code'])->toBe(0);

    $detail = $this->getJson('/api/products/'.$create['data']['id'])->json('data');

    expect($detail['brand']['name'])->toBe($brand->name)
        ->and($detail['weight'])->toBe(350)
        ->and($detail['attributes'])->toHaveCount(1)
        ->and($detail['attributes'][0]['value'])->toBe('铝合金');
});

test('TC-ATTR-020 商品详情返回视频与关键词字段', function () {
    $create = $this->postJson('/api/admin/products', [
        'category_id' => $this->categoryId,
        'title' => '视频商品',
        'status' => 1,
        'video_url' => 'https://cdn.example.com/v.mp4',
        'keywords' => '耳机,降噪',
        'skus' => [['specs' => ['规格' => '标准'], 'price' => '66.00', 'stock' => 1]],
    ], $this->adminAuth)->json();
    expect($create['code'])->toBe(0);

    $detail = $this->getJson('/api/products/'.$create['data']['id'])->json('data');
    expect($detail['video_url'])->toBe('https://cdn.example.com/v.mp4');
});
