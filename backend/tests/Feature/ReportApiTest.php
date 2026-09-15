<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * V1.1 T-020：经营报表聚合接口
 *
 * 覆盖：销售额口径（取消/退款剔除、退款中计入）、趋势补零、商品 TOP、
 *       分类占比合计、复购率、导出字段与区间上限、权限与参数校验。
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

    // 买家（无 report.view）
    $cap2 = app(CaptchaService::class)->generate();
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'reportbuyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];
});

/** 造一个买家账号（customer 角色） */
function reportBuyer(): SysUser
{
    $user = createTestUser('rb');
    $user->assignRole('customer');

    return $user;
}

/**
 * 造订单（可指定创建/支付时间与行项目）
 */
function seedReportOrder(int $userId, string $status, string $amount, ?string $createdAt = null, ?string $paidAt = null, array $items = []): Order
{
    static $seq = 0;
    $seq++;

    $order = Order::create([
        'order_no' => 'RP'.now()->format('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT).random_int(10, 99),
        'user_id' => $userId,
        'status' => $status,
        'total_amount' => $amount,
        'freight_amount' => '0.00',
        'pay_amount' => $amount,
        'address_snapshot' => ['contact_name' => '张三', 'contact_phone' => '13800000000'],
        'paid_at' => $paidAt ? Carbon::parse($paidAt) : null,
    ]);

    if ($createdAt) {
        $order->forceFill(['created_at' => Carbon::parse($createdAt)])->save();
    }

    foreach ($items as [$productId, $qty, $subtotal]) {
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'sku_id' => null,
            'product_title' => '商品'.$productId,
            'sku_specs' => ['规格' => '标准'],
            'price' => bcdiv($subtotal, (string) $qty, 2),
            'quantity' => $qty,
            'total_amount' => $subtotal,
        ]);
    }

    return $order;
}

function reportProduct(int $categoryId = 0): Product
{
    $categoryId = $categoryId ?: Category::create(['parent_id' => 0, 'name' => '报表分类'.uniqid(), 'sort' => 0, 'status' => 1])->id;

    return Product::create([
        'category_id' => $categoryId,
        'title' => '报表商品'.uniqid(),
        'price' => '10.00',
        'status' => 1,
    ]);
}

// ---------- overview ----------

test('TC-RPT-001 指标卡统计今日订单量/销售额/客单价/转化率', function () {
    $u = reportBuyer();
    // 今日 2 单已支付（100 + 200）+ 1 单待支付
    seedReportOrder($u->id, Order::STATUS_PAID, '100.00', now()->toDateTimeString(), now()->toDateTimeString());
    seedReportOrder($u->id, Order::STATUS_COMPLETED, '200.00', now()->toDateTimeString(), now()->toDateTimeString());
    seedReportOrder($u->id, Order::STATUS_PENDING_PAYMENT, '300.00', now()->toDateTimeString());

    $data = $this->getJson('/api/admin/reports/overview', $this->adminAuth)->json('data');

    expect($data['today']['orders'])->toBe(3)
        ->and($data['today']['paid_orders'])->toBe(2)
        ->and($data['today']['sales'])->toBe('300.00')
        ->and($data['today']['aov'])->toBe('150.00')
        ->and($data['today']['conversion_rate'])->toEqual(66.7);
});

test('TC-RPT-002 销售额口径：取消/已退款剔除，退款中计入', function () {
    $u = reportBuyer();
    $today = now()->toDateTimeString();

    seedReportOrder($u->id, Order::STATUS_PAID, '100.00', $today, $today);        // 计入
    seedReportOrder($u->id, Order::STATUS_REFUNDING, '50.00', $today, $today);    // 计入（在途）
    seedReportOrder($u->id, Order::STATUS_CANCELLED, '999.00', $today, $today);   // 剔除
    seedReportOrder($u->id, Order::STATUS_REFUNDED, '888.00', $today, $today);    // 剔除
    seedReportOrder($u->id, Order::STATUS_PENDING_PAYMENT, '777.00', $today);     // 未支付剔除

    $data = $this->getJson('/api/admin/reports/overview', $this->adminAuth)->json('data');

    expect($data['today']['sales'])->toBe('150.00')
        ->and($data['today']['paid_orders'])->toBe(2);
});

test('TC-RPT-003 待办聚合卡数值', function () {
    $u = reportBuyer();
    $today = now()->toDateTimeString();
    seedReportOrder($u->id, Order::STATUS_PAID, '10.00', $today, $today);       // 待发货
    seedReportOrder($u->id, Order::STATUS_REFUNDING, '10.00', $today, $today);  // 待退款

    $pending = $this->getJson('/api/admin/reports/overview', $this->adminAuth)->json('data.pending');

    expect($pending['ship'])->toBe(1)
        ->and($pending['refund'])->toBe(1)
        ->and($pending)->toHaveKeys(['review', 'stock_warning']);
});

// ---------- trend ----------

test('TC-RPT-004 趋势按天聚合且缺失日期补零', function () {
    $u = reportBuyer();
    // 3 天前 1 单，今天 1 单
    seedReportOrder($u->id, Order::STATUS_PAID, '100.00', now()->subDays(3)->toDateTimeString(), now()->subDays(3)->toDateTimeString());
    seedReportOrder($u->id, Order::STATUS_PAID, '250.00', now()->toDateTimeString(), now()->toDateTimeString());

    $data = $this->getJson('/api/admin/reports/trend?days=7', $this->adminAuth)->json('data');

    expect($data['days'])->toBe(7)
        ->and($data['series'])->toHaveCount(7);

    $byDate = collect($data['series'])->keyBy('date');
    $todayKey = now()->toDateString();
    $d3 = now()->subDays(3)->toDateString();

    expect($byDate[$todayKey]['sales'])->toBe('250.00')
        ->and($byDate[$todayKey]['orders'])->toBe(1)
        ->and($byDate[$d3]['sales'])->toBe('100.00')
        ->and($byDate[now()->subDays(1)->toDateString()]['orders'])->toBe(0)
        ->and($byDate[now()->subDays(1)->toDateString()]['sales'])->toBe('0.00');
});

test('TC-RPT-005 趋势 days 超出上限返回 422', function () {
    $this->getJson('/api/admin/reports/trend?days=200', $this->adminAuth)->assertStatus(422);
});

// ---------- top products ----------

test('TC-RPT-006 商品 TOP 按销量排序且金额正确', function () {
    $u = reportBuyer();
    $cat = Category::create(['parent_id' => 0, 'name' => 'TOP分类'.uniqid(), 'sort' => 0, 'status' => 1])->id;
    $p1 = reportProduct($cat);
    $p2 = reportProduct($cat);
    $today = now()->toDateTimeString();

    seedReportOrder($u->id, Order::STATUS_PAID, '300.00', $today, $today, [[$p1->id, 3, '300.00']]);
    seedReportOrder($u->id, Order::STATUS_PAID, '100.00', $today, $today, [[$p2->id, 1, '100.00']]);

    $list = $this->getJson('/api/admin/reports/top-products?days=30', $this->adminAuth)->json('data.list');

    expect($list)->toHaveCount(2)
        ->and($list[0]['product_id'])->toBe($p1->id)
        ->and($list[0]['quantity'])->toBe(3)
        ->and($list[0]['amount'])->toBe('300.00');
});

// ---------- category share ----------

test('TC-RPT-007 分类销售额占比合计 100%', function () {
    $u = reportBuyer();
    $catA = Category::create(['parent_id' => 0, 'name' => 'A类'.uniqid(), 'sort' => 0, 'status' => 1])->id;
    $catB = Category::create(['parent_id' => 0, 'name' => 'B类'.uniqid(), 'sort' => 0, 'status' => 1])->id;
    $pa = reportProduct($catA);
    $pb = reportProduct($catB);
    $today = now()->toDateTimeString();

    seedReportOrder($u->id, Order::STATUS_PAID, '300.00', $today, $today, [[$pa->id, 1, '300.00']]);
    seedReportOrder($u->id, Order::STATUS_PAID, '100.00', $today, $today, [[$pb->id, 1, '100.00']]);

    $data = $this->getJson('/api/admin/reports/category-share?days=30', $this->adminAuth)->json('data');

    expect($data['total'])->toBe('400.00')
        ->and($data['items'])->toHaveCount(2);

    $percentSum = array_sum(array_column($data['items'], 'percent'));
    expect(round($percentSum, 1))->toEqual(100.0);
    expect($data['items'][0]['percent'])->toEqual(75.0);
});

// ---------- users ----------

test('TC-RPT-008 复购率计算正确', function () {
    $u1 = reportBuyer();
    $u2 = reportBuyer();
    $u3 = reportBuyer();
    $today = now()->toDateTimeString();

    // u1 两笔完成 → 复购；u2 一笔完成 → 单次；u3 无完成订单
    seedReportOrder($u1->id, Order::STATUS_COMPLETED, '10.00', $today, $today);
    seedReportOrder($u1->id, Order::STATUS_COMPLETED, '10.00', $today, $today);
    seedReportOrder($u2->id, Order::STATUS_COMPLETED, '10.00', $today, $today);
    seedReportOrder($u3->id, Order::STATUS_PAID, '10.00', $today, $today);

    $data = $this->getJson('/api/admin/reports/users?days=30', $this->adminAuth)->json('data');

    expect($data['buyers'])->toBe(2)
        ->and($data['repeat_buyers'])->toBe(1)
        ->and($data['repurchase_rate'])->toEqual(50.0);
});

// ---------- export ----------

test('TC-RPT-009 导出区间明细字段完整', function () {
    $u = reportBuyer();
    $today = now()->toDateTimeString();
    $order = seedReportOrder($u->id, Order::STATUS_PAID, '123.45', $today, $today);
    Refund::create(['refund_no' => 'RF'.uniqid(), 'order_id' => $order->id, 'user_id' => $u->id, 'order_no' => $order->order_no, 'amount' => '23.45', 'reason' => 'x', 'status' => Refund::STATUS_SUCCESS]);

    $data = $this->getJson('/api/admin/reports/export?start='.now()->toDateString().'&end='.now()->toDateString(), $this->adminAuth)->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['truncated'])->toBeFalse()
        ->and($data['rows'][0])->toHaveKeys(['order_no', 'username', 'pay_amount', 'refund_amount', 'status', 'status_label', 'created_at', 'paid_at'])
        ->and($data['rows'][0]['pay_amount'])->toBe('123.45')
        ->and($data['rows'][0]['refund_amount'])->toBe('23.45');
});

test('TC-RPT-010 导出区间超过 90 天返回 422', function () {
    $this->getJson('/api/admin/reports/export?start=2026-01-01&end=2026-06-30', $this->adminAuth)->assertStatus(422);
});

test('TC-RPT-011 导出 end 早于 start 返回 422', function () {
    $this->getJson('/api/admin/reports/export?start=2026-09-10&end=2026-09-01', $this->adminAuth)->assertStatus(422);
});

// ---------- 权限与鉴权 ----------

test('TC-RPT-012 无 report.view 权限访问被拒', function () {
    $this->getJson('/api/admin/reports/overview', $this->buyerAuth)->assertStatus(403);
    $this->getJson('/api/admin/reports/trend', $this->buyerAuth)->assertStatus(403);
});

test('TC-RPT-013 未登录访问报表接口返回 401', function () {
    $this->getJson('/api/admin/reports/overview')->assertStatus(401);
});
