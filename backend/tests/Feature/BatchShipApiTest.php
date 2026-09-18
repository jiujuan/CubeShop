<?php

use App\Models\Order;
use App\Models\Shipping;
use App\Support\ShippingRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

/**
 * V1.1 T-044（E03）：批量发货（Excel 导入与逐行校验）
 *
 * 覆盖：① 100 行全成功；② 含错误行 → 全回滚 + 失败明细；③ 超上限拒绝；
 *       ④ 权限 403；⑤ 模板接口；⑥ 行校验单元（订单不存在/状态不符/公司无效/单号非法/文件内重复）。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

/** 建一笔待发货订单并返回订单号 */
function t044PendingShipOrder(): string
{
    $test = test();
    $user = createTestUser('t044'.uniqid());
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t044')->plainTextToken];

    $addr = $test->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');
    $addrId = $addr['id'] ?? $addr;

    $sku = createTestSku(stock: 10, price: '50.00');
    $test->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $auth)->assertOk();
    $order = $test->postJson('/api/orders', ['address_id' => $addrId], $auth)->json('data');

    $pay = $test->postJson('/api/payments', ['order_no' => $order['order_no'], 'channel' => 'wechat'], $auth)->json('data');
    $test->postJson('/api/payments/sandbox/'.($pay['payment_no'] ?? $pay['pay_params']['payment_no']), [], $auth);

    return $order['order_no'];
}

/** 造 xlsx 上传文件（rows 为不含表头的数据行） */
function t044Excel(array $rows): UploadedFile
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(ShippingRules::BATCH_SHIP_HEADERS, null, 'A1');
    $sheet->fromArray($rows, null, 'A2');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $path = tempnam(sys_get_temp_dir(), 't044').'.xlsx';
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'batch.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('TC-BSHIP-044-01 100 行全部成功且 1 分钟内完成', function () {
    $orderNos = [];
    for ($i = 0; $i < 100; $i++) {
        $orderNos[] = t044PendingShipOrder();
    }

    $rows = array_map(fn ($i, $no) => [$no, $i % 2 === 0 ? 'SF' : 'ZTO', strtoupper($i % 2 === 0 ? 'SF' : 'ZTO').sprintf('%08d', $i + 1)], array_keys($orderNos), $orderNos);

    $start = microtime(true);
    $res = $this->postJson('/api/admin/orders/batch-ship', ['file' => t044Excel($rows)], $this->adminAuth);
    $elapsed = microtime(true) - $start;

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.success'))->toBe(100)
        ->and($res->json('data.total'))->toBe(100)
        ->and(Shipping::count())->toBe(100)
        ->and(Order::where('status', Order::STATUS_SHIPPED)->count())->toBe(100)
        // 验收：100 行导入 1 分钟内完成
        ->and($elapsed)->toBeLessThan(60.0);

    // 冗余字段与 shipping 一致（抽查）
    $order = Order::where('order_no', $orderNos[0])->first();
    expect($order->tracking_no)->toBe($order->shipping->first()->tracking_no);
});

test('TC-BSHIP-044-02 含 3 行错误 → 全回滚 + 失败明细', function () {
    $ok1 = t044PendingShipOrder();
    $ok2 = t044PendingShipOrder();
    $bad = ['CS000000000000X', 'SF', 'SF0000000099']; // 订单不存在

    $res = $this->postJson('/api/admin/orders/batch-ship', ['file' => t044Excel([
        [$ok1, 'SF', 'SF0000000001'],
        $bad,
        [$ok2, 'XX', 'ZTO0000000001'], // 公司编码无效
        [$ok2, 'SF', 'SF0'], // 订单重复（文件内）+ 单号非法
    ])], $this->adminAuth);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('message'))->toContain('校验未全部通过')
        ->and(count($res->json('data.failed')))->toBe(3)
        // 全量不执行（预校验失败策略）
        ->and(Shipping::count())->toBe(0)
        ->and(Order::where('status', Order::STATUS_SHIPPED)->count())->toBe(0);

    $reasons = implode('|', array_column($res->json('data.failed'), 'reason'));
    expect($reasons)->toContain('订单不存在')
        ->and($reasons)->toContain('快递公司编码无效')
        ->and($reasons)->toContain('运单号格式错误');

    // 失败行带 Excel 行号
    $rows = array_column($res->json('data.failed'), 'row');
    expect($rows)->toBe([3, 4, 5]);
});

test('TC-BSHIP-044-03 超过 500 行上限被拒', function () {
    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = ['CS2026091700'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'SF', 'SF00000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)];
    }

    $res = $this->postJson('/api/admin/orders/batch-ship', ['file' => t044Excel($rows)], $this->adminAuth);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('message'))->toContain('单次最多');
});

test('TC-BSHIP-044-04 无权限返回 403', function () {
    $user = createTestUser('t044noperm');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->postJson('/api/admin/orders/batch-ship', ['file' => t044Excel([['x', 'SF', 'SF0000000001']])], $auth)
        ->assertStatus(403);
});

test('TC-BSHIP-044-05 模板下载返回 xlsx 且含表头', function () {
    $res = $this->getJson('/api/admin/orders/batch-ship/template', $this->adminAuth);

    expect($res->getStatusCode())->toBe(200)
        ->and($res->headers->get('content-disposition'))->toContain('xlsx');

    // 临时文件可被读回且表头正确
    $path = storage_path('app/batch-ship-template.xlsx');
    expect(is_file($path))->toBeTrue();
    $sheet = IOFactory::load($path)->getActiveSheet();
    $rows = $sheet->toArray(null, true, false, false);
    expect($rows[0])->toBe(ShippingRules::BATCH_SHIP_HEADERS);
});

test('TC-BSHIP-044-06 表头不符被拒', function () {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([['错误列头', '快递', '单号'], ['A', 'B', 'C']], null, 'A1');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $path = tempnam(sys_get_temp_dir(), 't044b').'.xlsx';
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();

    $file = new UploadedFile($path, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    $res = $this->postJson('/api/admin/orders/batch-ship', ['file' => $file], $this->adminAuth);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('message'))->toContain('表头');
});

test('TC-BSHIP-044-07 文件内运单号重复被拦截', function () {
    $ok1 = t044PendingShipOrder();
    $ok2 = t044PendingShipOrder();

    $res = $this->postJson('/api/admin/orders/batch-ship', ['file' => t044Excel([
        [$ok1, 'SF', 'SF0000000077'],
        [$ok2, 'SF', 'SF0000000077'],
    ])], $this->adminAuth);

    expect($res->json('code'))->toBe(40000)
        ->and($res->json('data.failed.0.reason'))->toContain('运单号在文件内重复')
        ->and(Shipping::count())->toBe(0);
});
