<?php

use App\Models\ExpressCompany;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\ShippingTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-047（E03）：快递字典维护接口与物流异常看板
 *
 * 覆盖：① 字典 CRUD（含编码冲突/删除保护）；② shipping.manage 权限 403；
 *       ③ 看板列表（筛选/异常标记 abnormal：failed 与发货超 48h 无轨迹）。
 */

beforeEach(function () {
    seedRoles();
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class);

    $cap = app(\App\Services\Common\CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

test('TC-SCOMP-047-01 字典 CRUD 与删除保护', function () {
    // 列表含种子 8 家（含停用）
    $list = $this->getJson('/api/admin/shipping-companies', $this->adminAuth);
    expect($list->json('data.pagination.total'))->toBeGreaterThanOrEqual(8);

    // 新增
    $create = $this->postJson('/api/admin/shipping-companies', [
        'code' => 'TESTX', 'name' => '测试快递', 'channel_code' => 'testx', 'sort' => 99, 'status' => 1,
    ], $this->adminAuth);
    expect($create->json('code'))->toBe(0)
        ->and($create->json('data.code'))->toBe('TESTX');

    // 编码冲突：表单校验 unique 先拦截（40000 参数校验失败）
    $dup = $this->postJson('/api/admin/shipping-companies', ['code' => 'TESTX', 'name' => '重复'], $this->adminAuth);
    expect($dup->json('code'))->toBe(40000)
        ->and($dup->json('message'))->toContain('参数校验失败');

    // 更新（停用）
    $id = $create->json('data.id');
    $upd = $this->putJson("/api/admin/shipping-companies/{$id}", ['name' => '测试快递二部', 'status' => 0], $this->adminAuth);
    expect($upd->json('code'))->toBe(0)
        ->and($upd->json('data.status'))->toBe(0)
        ->and($upd->json('data.name'))->toBe('测试快递二部');

    // 删除保护：被运单引用的禁止删除
    $sf = ExpressCompany::where('code', 'SF')->first();
    $sf->shippings()->create([
        'order_id' => 0, 'company_code' => 'SF', 'company_name' => '顺丰速运',
        'tracking_no' => 'SF047PROTECT1', 'trace_status' => Shipping::TRACE_PENDING,
    ]);
    $del = $this->deleteJson("/api/admin/shipping-companies/{$sf->id}", [], $this->adminAuth);
    expect($del->json('code'))->toBe(40009)
        ->and($del->json('message'))->toContain('不能删除');

    // 无运单引用的可删除
    $del2 = $this->deleteJson("/api/admin/shipping-companies/{$id}", [], $this->adminAuth);
    expect($del2->json('code'))->toBe(0);
});

test('TC-SCOMP-047-02 无 shipping.manage 权限返回 403', function () {
    $user = createTestUser('t047noperm');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->getJson('/api/admin/shipping-companies', $auth)->assertStatus(403);
    $this->postJson('/api/admin/shipping-companies', ['code' => 'X', 'name' => 'X'], $auth)->assertStatus(403);
});

test('TC-SCOMP-047-03 物流看板列表与异常标记', function () {
    $user = createTestUser('t047board'.uniqid());

    // 运单 A：failed（异常）
    $orderA = Order::create([
        'order_no' => 'CS047A'.uniqid(), 'user_id' => $user->id, 'status' => Order::STATUS_SHIPPED,
        'total_amount' => '10.00', 'freight_amount' => '0.00', 'pay_amount' => '10.00',
        'address_snapshot' => ['name' => 'A', 'phone' => '13800000001'],
    ]);
    $shipA = Shipping::create([
        'order_id' => $orderA->id, 'company_code' => 'SF', 'company_name' => '顺丰速运',
        'tracking_no' => 'SF047BOARDAA', 'trace_status' => Shipping::TRACE_FAILED,
        'shipped_at' => now()->subDays(3), 'pull_fail_count' => 5, 'last_fail_message' => '查无此单',
    ]);

    // 运单 B：发货 72h 仍无轨迹（异常：发货超 48h 无轨迹）
    $orderB = Order::create([
        'order_no' => 'CS047B'.uniqid(), 'user_id' => $user->id, 'status' => Order::STATUS_SHIPPED,
        'total_amount' => '10.00', 'freight_amount' => '0.00', 'pay_amount' => '10.00',
        'address_snapshot' => ['name' => 'B', 'phone' => '13800000002'],
    ]);
    $shipB = Shipping::create([
        'order_id' => $orderB->id, 'company_code' => 'ZTO', 'company_name' => '中通快递',
        'tracking_no' => 'ZTO047BOARD0', 'trace_status' => Shipping::TRACE_PENDING,
        'shipped_at' => now()->subDays(3),
    ]);

    // 运单 C：正常（昨天发货，今日已拉到轨迹）
    $orderC = Order::create([
        'order_no' => 'CS047C'.uniqid(), 'user_id' => $user->id, 'status' => Order::STATUS_SHIPPED,
        'total_amount' => '10.00', 'freight_amount' => '0.00', 'pay_amount' => '10.00',
        'address_snapshot' => ['name' => 'C', 'phone' => '13800000003'],
    ]);
    $shipC = Shipping::create([
        'order_id' => $orderC->id, 'company_code' => 'SF', 'company_name' => '顺丰速运',
        'tracking_no' => 'SF047BOARDCC', 'trace_status' => Shipping::TRACE_IN_TRANSIT,
        'shipped_at' => now()->subDay(),
    ]);
    ShippingTrace::create(['shipping_id' => $shipC->id, 'context' => '揽收', 'occurred_at' => now()->subHours(20)]);

    $res = $this->getJson('/api/admin/shippings', $this->adminAuth);
    $rows = collect($res->json('data.list'));
    $rowA = $rows->firstWhere('id', $shipA->id);
    $rowB = $rows->firstWhere('id', $shipB->id);
    $rowC = $rows->firstWhere('id', $shipC->id);

    expect($res->json('code'))->toBe(0)
        ->and($rowA['abnormal'])->toBeTrue()
        ->and($rowA['last_fail_message'])->toBe('查无此单')
        ->and($rowB['abnormal'])->toBeTrue()
        ->and($rowC['abnormal'])->toBeFalse()
        ->and($rowC['trace_count'])->toBe(1)
        ->and($rowA['order_no'])->toBe($orderA->order_no);

    // failed 筛选
    $filtered = $this->getJson('/api/admin/shippings?trace_status=failed', $this->adminAuth);
    $ids = collect($filtered->json('data.list'))->pluck('id');
    expect($ids)->toContain($shipA->id)
        ->and($ids)->not->toContain($shipC->id);

    // keyword 筛选（订单号）
    $kw = $this->getJson("/api/admin/shippings?keyword={$orderB->order_no}", $this->adminAuth);
    expect(collect($kw->json('data.list'))->pluck('id'))->toContain($shipB->id);
});
