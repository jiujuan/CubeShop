<?php

use App\Models\ExpressCompany;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Shipping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * V1.1 T-043（E03）：发货接口升级、订单冗余双号与导出扩展
 *
 * 覆盖：正常发货（shipping 记录 + 冗余字段一致）、公司编码无效 422、单号格式 422 各分支、
 *       重复发货被拒、非 paid/pending_ship 状态被拒、权限 403、通知内容含双号、导出新列。
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

function t043Buyer(): array
{
    $user = createTestUser('t043'.uniqid());

    return ['user' => $user, 'auth' => ['Authorization' => 'Bearer '.$user->createToken('t043')->plainTextToken]];
}

/** 建一个待发货订单（支付成功自动流转到 pending_ship） */
function t043PendingShipOrder(array $buyer): int
{
    $test = test();
    $addr = $test->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $buyer['auth'])->json('data');
    $addrId = $addr['id'] ?? $addr;

    $sku = createTestSku(stock: 10, price: '50.00');
    $test->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $buyer['auth'])->assertOk();
    $order = $test->postJson('/api/orders', ['address_id' => $addrId], $buyer['auth'])->json('data');

    $pay = $test->postJson('/api/payments', ['order_no' => $order['order_no'], 'channel' => 'wechat'], $buyer['auth'])->json('data');
    $test->postJson('/api/payments/sandbox/'.($pay['payment_no'] ?? $pay['pay_params']['payment_no']), [], $buyer['auth']);

    return oid($order['order_id']);
}

test('TC-SHIP-043-01 正常发货成功且 shipping 记录与订单冗余字段一致', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    $res = $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF100000099', 'remark' => '当日达',
    ], $this->adminAuth)->assertOk();

    expect($res->json('code'))->toBe(0)
        ->and($res->json('data.status'))->toBe(Order::STATUS_SHIPPED)
        ->and($res->json('data.express_company'))->toBe('顺丰速运')
        ->and($res->json('data.tracking_no'))->toBe('SF100000099')
        ->and($res->json('data.shipped_at'))->not->toBeNull();

    // shipping 记录（名称快照 + pending 初始态）
    $shipping = Shipping::where('order_id', oid($orderId))->first();
    expect($shipping)->not->toBeNull()
        ->and($shipping->company_code)->toBe('SF')
        ->and($shipping->company_name)->toBe('顺丰速运')
        ->and($shipping->tracking_no)->toBe('SF100000099')
        ->and($shipping->trace_status)->toBe(Shipping::TRACE_PENDING)
        ->and($shipping->shipped_at)->not->toBeNull();

    // 订单冗余字段与 shipping 一致
    $order = Order::find(oid($orderId));
    expect($order->express_company)->toBe($shipping->company_name)
        ->and($order->tracking_no)->toBe($shipping->tracking_no);

    // order_logs 有 ship 流水（remark 含单号）
    $log = OrderLog::where('order_id', oid($orderId))->where('to_status', Order::STATUS_SHIPPED)->first();
    expect($log)->not->toBeNull()
        ->and($log->remark)->toContain('SF100000099');
});

test('TC-SHIP-043-02 公司编码无效或停用返回 422', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    // 不存在的编码
    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'XX', 'tracking_no' => 'SF12345678',
    ], $this->adminAuth)->assertStatus(422);

    // 停用的编码
    ExpressCompany::where('code', 'YTO')->update(['status' => 0]);
    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'YTO', 'tracking_no' => 'SF12345679',
    ], $this->adminAuth)->assertStatus(422);

    // 未产生发货与状态变更
    expect(Shipping::where('order_id', oid($orderId))->count())->toBe(0)
        ->and(Order::find(oid($orderId))->status)->toBe(Order::STATUS_PENDING_SHIP);
});

test('TC-SHIP-043-03 单号格式错误各分支返回 422', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    foreach ([
        'SF1234' => '过短（<8 位）',
        'SF123456789012345678901234567890123' => '过长（>32 位）',
        'SF1234中文78' => '含中文',
        'SF1234*678' => '含非法符号',
    ] as $badNo => $case) {
        $res = $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
            'express_company_code' => 'SF', 'tracking_no' => $badNo,
        ], $this->adminAuth);

        expect($res->getStatusCode())->toBe(422);
    }

    // 含空格 → 自动去空格后发货成功（合法边界）
    $res = $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF 1234 5678',
    ], $this->adminAuth)->assertOk();
    expect($res->json('data.tracking_no'))->toBe('SF12345678')
        ->and(Shipping::where('order_id', oid($orderId))->count())->toBe(1);
});

test('TC-SHIP-043-04 重复发货被拒且同运单号不能挂两单', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF66600001',
    ], $this->adminAuth)->assertOk();

    // 已发货订单再次调用 → 业务码拒绝
    $again = $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF66600002',
    ], $this->adminAuth);
    expect($again->json('code'))->toBe(40009)
        ->and(Shipping::where('order_id', oid($orderId))->count())->toBe(1);

    // 同运单号挂到另一个订单 → 409
    ['user' => $user2, 'auth' => $auth2] = t043Buyer();
    $orderId2 = t043PendingShipOrder(['user' => $user2, 'auth' => $auth2]);
    $dup = $this->postJson('/api/admin/orders/'.oid($orderId2).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF66600001',
    ], $this->adminAuth);
    expect($dup->json('code'))->toBe(40009);
});

test('TC-SHIP-043-05 非 pending_ship 状态发货被拒', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    // 待支付订单（未支付）
    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $auth)->json('data');
    $addrId = $addr['id'] ?? $addr;
    $sku = createTestSku(stock: 10, price: '50.00');
    $this->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $auth)->assertOk();
    $order = $this->postJson('/api/orders', ['address_id' => $addrId], $auth)->json('data');

    $res = $this->postJson('/api/admin/orders/'.oid($order['order_id']).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF77777777',
    ], $this->adminAuth);

    expect($res->json('code'))->toBe(40009)
        ->and(Order::find(oid($order['order_id']))->status)->toBe(Order::STATUS_PENDING_PAYMENT);
});

test('TC-SHIP-043-06 无 order.ship 权限返回 403', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    // 无 token → 401；普通管理员角色场景在角色权限用例覆盖，这里验证买家 token 不可用
    $res = $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF88888888',
    ], $auth);

    expect($res->getStatusCode())->toBe(403);
});

test('TC-SHIP-043-07 发货通知内容包含快递公司与运单号', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'ZTO', 'tracking_no' => 'ZTO66600099',
    ], $this->adminAuth)->assertOk();

    $notification = \Illuminate\Support\Facades\DB::table('notifications')
        ->where('user_id', $user->id)
        ->where('type', \App\Services\Notification\NotificationService::TYPE_ORDER_SHIPPED)
        ->orderByDesc('id')->first();

    expect($notification)->not->toBeNull()
        ->and($notification->content)->toContain('中通快递')
        ->and($notification->content)->toContain('ZTO66600099');
});

test('TC-SHIP-043-08 订单导出包含快递公司/运单号/确认收货时间/系统自动确认列', function () {
    ['user' => $user, 'auth' => $auth] = t043Buyer();
    $orderId = t043PendingShipOrder(compact('user', 'auth'));

    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', [
        'express_company_code' => 'SF', 'tracking_no' => 'SF99900088',
    ], $this->adminAuth)->assertOk();

    $csv = $this->getJson('/api/admin/orders/export', $this->adminAuth)->streamedContent();

    expect($csv)->toContain('快递公司')
        ->and($csv)->toContain('运单号')
        ->and($csv)->toContain('确认收货时间')
        ->and($csv)->toContain('系统自动确认')
        ->and($csv)->toContain('顺丰速运')
        ->and($csv)->toContain('SF99900088');
});
