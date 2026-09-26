<?php

use App\Models\Inventory;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\InventoryLog;
use App\Services\Common\CaptchaService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * 库存盘点
 *
 * 覆盖：建单与账面快照、按分类/自定义清单圈选、实盘录入与统计、Excel 回填、
 *       导出、过账按实时库存调差、锁定库存冲突跳过、重复过账幂等、作废、
 *       权限 403、库存流水按 biz_type/biz_id 反查。
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

/** 造 xlsx 上传文件（$rows 为数据行；给 $header 时数据从第二行开始） */
function icheckExcel(array $rows, ?array $header = null): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    if ($header === null) {
        $sheet->fromArray($rows, null, 'A1');
    } else {
        $sheet->fromArray($header, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
    }
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path = tempnam(sys_get_temp_dir(), 'icx').'.xlsx');
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'items.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** 建盘点单 */
function icheckCreate($test, array $payload)
{
    return $test->postJson('/api/admin/inventory-checks', $payload, $test->adminAuth);
}

test('建单生成明细并快照开单时账面库存', function () {
    $skuA = createTestSku(stock: 10, price: '50.00');
    $skuB = createTestSku(stock: 4, price: '80.00');

    $res = icheckCreate($this, ['title' => '全盘', 'scope_type' => 'all']);
    $res->assertOk();

    $id = $res->json('data.id');
    expect($res->json('data.check_no'))->toMatch('/^PC\d{8}\d{4}$/')
        ->and($res->json('data.item_count'))->toBe(2)
        ->and($res->json('data.status'))->toBe('draft');

    $items = InventoryCheckItem::where('check_id', $id)->orderBy('sku_id')->get();
    expect($items->pluck('sku_id')->all())->toBe([$skuA->id, $skuB->id])
        ->and($items[0]->system_qty)->toBe(10)
        ->and($items[1]->system_qty)->toBe(4)
        ->and($items[0]->status)->toBe('pending');
});

test('按分类建单包含其下子分类商品', function () {
    $parent = \App\Models\Category::create(['parent_id' => 0, 'name' => '父分类', 'sort' => 0, 'status' => 1]);
    $child = \App\Models\Category::create(['parent_id' => $parent->id, 'name' => '子分类', 'sort' => 0, 'status' => 1]);

    $inParent = createTestSku(stock: 3, price: '10.00');
    $inChild = createTestSku(stock: 5, price: '10.00');
    $other = createTestSku(stock: 7, price: '10.00');

    $inParent->product->update(['category_id' => $parent->id]);
    $inChild->product->update(['category_id' => $child->id]);
    $otherCategory = \App\Models\Category::create(['parent_id' => 0, 'name' => '其它分类', 'sort' => 0, 'status' => 1]);
    $other->product->update(['category_id' => $otherCategory->id]);

    $res = icheckCreate($this, ['scope_type' => 'category', 'scope_value' => (string) $parent->id]);
    $res->assertOk();

    expect(InventoryCheckItem::where('check_id', $res->json('data.id'))->pluck('sku_id')->sort()->all())
        ->toBe(collect([$inParent->id, $inChild->id])->sort()->all());
});

test('自定义清单模式按上传的 SKU 编码建单', function () {
    $sku = createTestSku(stock: 6, price: '10.00');
    createTestSku(stock: 6, price: '10.00');

    $res = $this->postJson('/api/admin/inventory-checks', [
        'scope_type' => 'custom',
        'file' => icheckExcel([[$sku->sku_code]]),
    ], $this->adminAuth);

    $res->assertOk();
    expect($res->json('data.item_count'))->toBe(1)
        ->and(InventoryCheckItem::where('check_id', $res->json('data.id'))->first()->sku_id)->toBe($sku->id);
});

test('录入实盘后状态流转并更新统计', function () {
    $sku = createTestSku(stock: 10, price: '10.00');
    $id = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');
    $item = InventoryCheckItem::where('check_id', $id)->first();

    $res = $this->postJson("/api/admin/inventory-checks/{$id}/count", [
        'items' => [['item_id' => $item->id, 'counted_qty' => 7]],
    ], $this->adminAuth);
    $res->assertOk();

    $item->refresh();
    expect($item->counted_qty)->toBe(7)
        ->and($item->status)->toBe('counted')
        ->and(InventoryCheck::find($id)->status)->toBe('counting')
        ->and(InventoryCheck::find($id)->diff_count)->toBe(1);
});

test('Excel 回填实盘：成功写入 / 含错误行则整批不写', function () {
    $sku = createTestSku(stock: 10, price: '10.00');
    $id = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');

    // 成功
    $res = $this->postJson("/api/admin/inventory-checks/{$id}/import", [
        'file' => icheckExcel([[$sku->sku_code, '9', '盘点备注']], ['SKU编码', '实盘数量', '备注']),
    ], $this->adminAuth);
    $res->assertOk();
    expect(InventoryCheckItem::where('check_id', $id)->first()->counted_qty)->toBe(9);

    // 含不属于本单的 SKU → 整批拒绝
    $res = $this->postJson("/api/admin/inventory-checks/{$id}/import", [
        'file' => icheckExcel([[strrev($sku->sku_code), '9', '']], ['SKU编码', '实盘数量', '备注']),
    ], $this->adminAuth);
    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('不在本盘点单内')
        ->and(InventoryCheckItem::where('check_id', $id)->first()->counted_qty)->toBe(9);
});

test('导出明细 CSV 含表头与数量列', function () {
    $sku = createTestSku(stock: 10, price: '10.00');
    $id = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');

    $res = $this->get("/api/admin/inventory-checks/{$id}/export", $this->adminAuth);
    $res->assertOk();

    $content = $res->streamedContent();
    expect($content)->toContain('SKU编码')
        ->and($content)->toContain($sku->sku_code);
});

test('过账按「过账时刻实时库存」计算差异并留可反查的流水', function () {
    $sku = createTestSku(stock: 10, price: '10.00');
    $id = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');
    $item = InventoryCheckItem::where('check_id', $id)->first();

    // 盘点期间发生销售：账面 10 → 7（快照仍是 10，仅展示用）
    app(InventoryService::class)->adjust($sku->id, -3, null, '模拟销售出库', 'order');

    $this->postJson("/api/admin/inventory-checks/{$id}/count", [
        'items' => [['item_id' => $item->id, 'counted_qty' => 8]],
    ], $this->adminAuth)->assertOk();

    $res = $this->postJson("/api/admin/inventory-checks/{$id}/post", [], $this->adminAuth);
    $res->assertOk();
    expect($res->json('data.adjusted'))->toBe(1);

    // 实盘 8 - 过账时刻账面 7 = +1（而不是按快照 10 算的 -2）
    expect(Inventory::where('sku_id', $sku->id)->first()->stock)->toBe(8);
    $item->refresh();
    expect($item->diff_qty)->toBe(1)
        ->and($item->system_qty)->toBe(10)
        ->and(InventoryCheck::find($id)->status)->toBe('posted');

    // 流水可按 biz_type + biz_id 反查整张盘点单
    $log = InventoryLog::where('biz_type', 'inventory_check')->where('biz_id', $id)->first();
    expect($log)->not->toBeNull()
        ->and($log->change_qty)->toBe(1)
        ->and($log->remark)->toContain('盘点单');
});

test('可用库存不足（锁定库存占用）的行被跳过而非整单失败', function () {
    $sku = createTestSku(stock: 10, price: '10.00');

    // 8 件被未支付订单锁定：stock 2 / locked 8
    app(InventoryService::class)->lock($sku->id, 8, 'order');

    $id = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');
    $item = InventoryCheckItem::where('check_id', $id)->first();

    $this->postJson("/api/admin/inventory-checks/{$id}/count", [
        'items' => [['item_id' => $item->id, 'counted_qty' => 0]],
    ], $this->adminAuth)->assertOk();

    $res = $this->postJson("/api/admin/inventory-checks/{$id}/post", [], $this->adminAuth);
    $res->assertOk();
    expect($res->json('data.skipped'))->toBe(1)
        ->and($res->json('data.adjusted'))->toBe(0);

    $item->refresh();
    expect($item->status)->toBe('skipped')
        ->and($item->remark)->toContain('锁定')
        // 库存未被改动
        ->and(Inventory::where('sku_id', $sku->id)->first()->stock)->toBe(2);
});

test('重复过账与作废后过账均被拒绝', function () {
    $sku = createTestSku(stock: 10, price: '10.00');
    $id = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');
    $item = InventoryCheckItem::where('check_id', $id)->first();
    $this->postJson("/api/admin/inventory-checks/{$id}/count", [
        'items' => [['item_id' => $item->id, 'counted_qty' => 10]],
    ], $this->adminAuth)->assertOk();

    $this->postJson("/api/admin/inventory-checks/{$id}/post", [], $this->adminAuth)->assertOk();

    // 第二次
    $again = $this->postJson("/api/admin/inventory-checks/{$id}/post", [], $this->adminAuth);
    expect($again->json('code'))->toBe(40000)
        ->and($again->json('message'))->toContain('已过账');

    // 作废路径：新建一单后作废
    $id2 = icheckCreate($this, ['scope_type' => 'all'])->json('data.id');
    $this->postJson("/api/admin/inventory-checks/{$id2}/cancel", [], $this->adminAuth)->assertOk();
    $this->postJson("/api/admin/inventory-checks/{$id2}/cancel", [], $this->adminAuth);

    $post = $this->postJson("/api/admin/inventory-checks/{$id2}/post", [], $this->adminAuth);
    expect($post->json('code'))->toBe(40000)
        ->and($post->json('message'))->toContain('已作废');
});

test('无权限账号访问盘点接口返回 403', function () {
    createTestSku(stock: 10, price: '10.00');
    $user = createTestUser('icnoperm');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->getJson('/api/admin/inventory-checks', $auth)->assertStatus(403);
    $this->postJson('/api/admin/inventory-checks', ['scope_type' => 'all'], $auth)->assertStatus(403);
});
