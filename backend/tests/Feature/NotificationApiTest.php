<?php

use App\Jobs\SendNotificationMail;
use App\Models\Notification;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * V1.1 T-018 / T-019：通知服务（站内信 + 邮件通道）与通知中心接口
 *
 * 覆盖：各业务事件的站内信落地、角色广播（库存预警）、未读数、
 *       列表筛选、部分/全部已读、数据隔离、邮件通道开关、鉴权。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $this->seed(\Database\Seeders\ExpressCompanySeeder::class); // T-043 发货需要快递字典

    // 管理员
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin', 'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 买家
    $cap2 = app(CaptchaService::class)->generate();
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'notify'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];
    $this->buyerId = $this->getJson('/api/auth/me', $this->buyerAuth)->json('data.id');

    // 运营账号（库存预警接收方）
    $this->operatorId = (int) SysUser::where('username', 'operator')->value('id');

    // 收货地址 + SKU
    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
    ], $this->buyerAuth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 20, price: '50.00');
});

/** 下单 → 支付（沙箱），返回 order_id */
function notifyPayOrder($test): int
{
    $test->postJson('/api/cart', ['sku_id' => $test->sku->id, 'quantity' => 1], $test->buyerAuth);
    $order = $test->postJson('/api/orders', ['address_id' => $test->addressId], $test->buyerAuth)->json('data');
    $pay = $test->postJson('/api/payments', ['order_no' => $order['order_no'], 'channel' => 'wechat'], $test->buyerAuth)->json('data');
    $payNo = $pay['payment_no'] ?? ($pay['pay_params']['payment_no'] ?? null);
    $test->postJson("/api/payments/sandbox/{$payNo}", [], $test->buyerAuth);

    return oid($order['order_id']);
}

// ---------- T-018 事件驱动的站内信 ----------

test('TC-NOTIFY-001 支付成功通知买家', function () {
    notifyPayOrder($this);

    $n = Notification::where('user_id', $this->buyerId)->where('type', NotificationService::TYPE_ORDER_PAID)->first();
    expect($n)->not->toBeNull()
        ->and($n->title)->toBe('支付成功')
        ->and($n->is_read)->toBeFalse()
        ->and($n->link)->toContain('/orders/');
});

test('TC-NOTIFY-002 发货通知买家', function () {
    $orderId = notifyPayOrder($this);
    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', ['express_company_code' => 'SF', 'tracking_no' => 'SF55500001'], $this->adminAuth)->assertStatus(200);

    $n = Notification::where('user_id', $this->buyerId)->where('type', NotificationService::TYPE_ORDER_SHIPPED)->first();
    expect($n)->not->toBeNull()
        ->and($n->title)->toBe('订单已发货')
        ->and($n->content)->toContain('已发货');
});

test('TC-NOTIFY-003 退款结果通知买家', function () {
    $orderId = notifyPayOrder($this);
    // 买家申请退款
    $this->postJson("/api/orders/{$orderId}/refund", ['reason' => '不想要了'], $this->buyerAuth)->assertStatus(200);
    $refundId = \App\Models\Refund::where('order_id', oid($orderId))->value('id');

    // 后台退款处理：同意并标记已退款
    $this->postJson('/api/admin/refunds/'.rfid($refundId).'/process', ['action' => 'approve', 'admin_remark' => '同意'], $this->adminAuth)
        ->assertStatus(200);

    expect(Notification::where('user_id', $this->buyerId)->where('type', NotificationService::TYPE_REFUND_RESULT)->count())
        ->toBeGreaterThan(0);
});

test('TC-NOTIFY-004 库存跌破阈值广播运营角色', function () {
    // stock=11，锁 1 → 穿越阈值 10
    $sku = createTestSku(stock: 11, price: '9.90');

    $this->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $this->buyerAuth);
    $this->postJson('/api/orders', ['address_id' => $this->addressId], $this->buyerAuth)->assertStatus(200);

    $n = Notification::where('user_id', $this->operatorId)->where('type', NotificationService::TYPE_LOW_STOCK)->first();
    expect($n)->not->toBeNull()
        ->and($n->content)->toContain($sku->sku_code);
});

test('TC-NOTIFY-005 未跌破阈值不产生预警', function () {
    $sku = createTestSku(stock: 50, price: '9.90');

    $this->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $this->buyerAuth);
    $this->postJson('/api/orders', ['address_id' => $this->addressId], $this->buyerAuth)->assertStatus(200);

    expect(Notification::where('type', NotificationService::TYPE_LOW_STOCK)->count())->toBe(0);
});

// ---------- T-018 邮件通道 ----------

test('TC-NOTIFY-006 命中配置类型的通知投递邮件作业', function () {
    Queue::fake();
    $this->seed(\Database\Seeders\ReviewNotifySeeder::class); // notify.mail_types = ["order_paid"]

    notifyPayOrder($this);

    Queue::assertPushed(SendNotificationMail::class);
});

test('TC-NOTIFY-007 未配置类型不投递邮件', function () {
    Queue::fake();
    $this->seed(\Database\Seeders\ReviewNotifySeeder::class); // 仅 order_paid 走邮件

    $orderId = notifyPayOrder($this);
    Queue::fake(); // 重置，排除支付那次
    $this->postJson('/api/admin/orders/'.oid($orderId).'/ship', ['express_company_code' => 'SF', 'tracking_no' => 'SF55500001'], $this->adminAuth)->assertStatus(200);

    Queue::assertNotPushed(SendNotificationMail::class);
});

test('TC-NOTIFY-008 邮件作业对无邮箱用户静默跳过', function () {
    // 买家无邮箱 → job handle 直接 return，不抛异常
    $job = new SendNotificationMail($this->buyerId, '标题', '内容');
    expect(fn () => $job->handle())->not->toThrow(\Throwable::class);
});

// ---------- T-019 通知中心接口 ----------

test('TC-NOTIFY-009 未读数接口', function () {
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'a']);
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'b']);
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'c', 'is_read' => true, 'read_at' => now()]);

    $resp = $this->getJson('/api/me/notifications/unread-count', $this->buyerAuth)->json('data');

    expect($resp['count'])->toBe(2);
});

test('TC-NOTIFY-010 通知列表按已读筛选', function () {
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'unread-1']);
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'read-1', 'is_read' => true, 'read_at' => now()]);

    $all = $this->getJson('/api/me/notifications', $this->buyerAuth)->json('data');
    expect($all['pagination']['total'])->toBe(2);

    $unread = $this->getJson('/api/me/notifications?is_read=0', $this->buyerAuth)->json('data');
    expect($unread['pagination']['total'])->toBe(1)
        ->and($unread['list'][0]['title'])->toBe('unread-1');

    $read = $this->getJson('/api/me/notifications?is_read=1', $this->buyerAuth)->json('data');
    expect($read['pagination']['total'])->toBe(1)
        ->and($read['list'][0]['title'])->toBe('read-1');
});

test('TC-NOTIFY-011 标记指定通知已读', function () {
    $a = Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'a']);
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'b']);

    $resp = $this->postJson('/api/me/notifications/read', ['ids' => [$a->id]], $this->buyerAuth)->json('data');

    expect($resp['updated'])->toBe(1)
        ->and($a->fresh()->is_read)->toBeTrue()
        ->and(Notification::where('user_id', $this->buyerId)->where('is_read', false)->count())->toBe(1);
});

test('TC-NOTIFY-012 ids 为空表示全部已读', function () {
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'a']);
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'b']);

    $resp = $this->postJson('/api/me/notifications/read', [], $this->buyerAuth)->json('data');

    expect($resp['updated'])->toBe(2)
        ->and(Notification::where('user_id', $this->buyerId)->where('is_read', false)->count())->toBe(0);
});

test('TC-NOTIFY-013 通知数据隔离（仅见本人）', function () {
    Notification::create(['user_id' => $this->buyerId, 'type' => 't', 'title' => 'mine']);
    Notification::create(['user_id' => 999999, 'type' => 't', 'title' => 'others']);

    $resp = $this->getJson('/api/me/notifications', $this->buyerAuth)->json('data');

    expect($resp['pagination']['total'])->toBe(1)
        ->and($resp['list'][0]['title'])->toBe('mine');
});

test('TC-NOTIFY-014 不能标记他人通知为已读', function () {
    $other = Notification::create(['user_id' => 999999, 'type' => 't', 'title' => 'others']);

    $resp = $this->postJson('/api/me/notifications/read', ['ids' => [$other->id]], $this->buyerAuth)->json('data');

    expect($resp['updated'])->toBe(0)
        ->and($other->fresh()->is_read)->toBeFalse();
});

test('TC-NOTIFY-015 未登录访问通知接口返回 401', function () {
    $this->getJson('/api/me/notifications')->assertStatus(401);
    $this->getJson('/api/me/notifications/unread-count')->assertStatus(401);
    $this->postJson('/api/me/notifications/read', [])->assertStatus(401);
});

// ---------- V1.1 用户表拆分：收件人身份隔离 ----------

test('TC-NOTIFY-016 运营通知不会串号给同 ID 买家（ID 撞号隔离）', function () {
    $operatorId = $this->operatorId;

    // 人为制造 ID 撞号：users 表中放一个与 operator 同 ID 的买家
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'id' => $operatorId, 'username' => 'collide'.uniqid(), 'password' => bcrypt('Test@1234'),
        'nickname' => '撞号买家', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // 同一 user_id：一条是发给运营的库存预警，一条是发给买家的支付通知
    Notification::create([
        'user_id' => $operatorId, 'receiver_type' => Notification::RECEIVER_ADMIN,
        'type' => NotificationService::TYPE_LOW_STOCK, 'title' => '库存预警', 'content' => 'x', 'is_read' => false,
    ]);
    Notification::create([
        'user_id' => $operatorId, 'receiver_type' => Notification::RECEIVER_CUSTOMER,
        'type' => NotificationService::TYPE_ORDER_PAID, 'title' => '支付成功', 'content' => 'y', 'is_read' => false,
    ]);

    // 买家身份：只看到自己的 1 条，看不到运营的库存预警
    $buyerToken = \App\Models\User::find($operatorId)->createToken('t')->plainTextToken;
    $buyerAuth = ['Authorization' => 'Bearer '.$buyerToken];

    expect($this->getJson('/api/me/notifications/unread-count', $buyerAuth)->json('data.count'))->toBe(1);
    $buyerList = $this->getJson('/api/me/notifications', $buyerAuth)->json('data.list');
    expect(array_column($buyerList, 'title'))->toBe(['支付成功']);

    // 管理员身份：看到的是运营的库存预警
    $adminToken = SysUser::find($operatorId)->createToken('t')->plainTextToken;
    $adminAuth = ['Authorization' => 'Bearer '.$adminToken];

    expect($this->getJson('/api/me/notifications/unread-count', $adminAuth)->json('data.count'))->toBe(1);
    $adminList = $this->getJson('/api/me/notifications', $adminAuth)->json('data.list');
    expect(array_column($adminList, 'title'))->toBe(['库存预警']);

    // 买家「全部已读」不会误改运营的预警
    $this->postJson('/api/me/notifications/read', [], $buyerAuth)->assertOk();
    expect(Notification::where('receiver_type', Notification::RECEIVER_ADMIN)->first()->is_read)->toBeFalse();
});
