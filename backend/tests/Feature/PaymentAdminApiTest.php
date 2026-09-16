<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\SysOperationLog;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 集成测试：后台支付管理（payments / payment_logs，API 文档 8.11 / 8.12）
 * 走真实 HTTP + 权限中间件 + 数据库，覆盖权限分层、筛选、详情留痕、关闭、导出。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $login = function (string $username, string $password) {
        $cap = app(CaptchaService::class)->generate();

        return $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token');
    };

    $this->adminAuth = ['Authorization' => 'Bearer '.$login('admin', 'Admin@123')];
    $this->operatorAuth = ['Authorization' => 'Bearer '.$login('operator', 'Operator@123')];

    // 前台买家
    $cap = app(CaptchaService::class)->generate();
    $this->buyerUsername = 'buyer'.uniqid();
    $this->buyerToken = $this->postJson('/api/auth/register', [
        'username' => $this->buyerUsername,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->buyerToken];
    $this->buyer = User::where('username', $this->buyerUsername)->first();

    $this->address = UserAddress::create([
        'user_id' => $this->buyer->id,
        'contact_name' => '张三',
        'contact_phone' => '13800001111',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
        'detail_address' => '科技园南路 88 号',
        'is_default' => true,
    ]);
});

/** 下单并发起支付（不回调），返回 [order, payment_no, amount] */
function preparePayment($test, string $channel = 'wechat'): array
{
    $sku = createTestSku(stock: 10, price: '60.00');
    $test->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $test->buyerAuth);
    $order = $test->postJson('/api/orders', ['address_id' => $test->address->id], $test->buyerAuth)->json('data');

    $pay = $test->postJson('/api/payments', [
        'order_no' => $order['order_no'],
        'channel' => $channel,
    ], $test->buyerAuth)->json('data');

    return [$order, $pay['payment_no'], $pay['amount']];
}

// 权限分层：买家 403；运营可查看不可关闭；超管可关闭
test('TC-PAY-001 权限分层：买家 403、运营可看不可关、超管可关', function () {
    [$order, $paymentNo] = preparePayment($this);

    $payment = Payment::where('payment_no', $paymentNo)->firstOrFail();

    $this->getJson('/api/admin/payments', $this->buyerAuth)->assertStatus(403);
    $this->getJson('/api/admin/payment-logs', $this->buyerAuth)->assertStatus(403);

    $opList = $this->getJson('/api/admin/payments', $this->operatorAuth);
    expect($opList->json('code'))->toBe(0)
        ->and($opList->json('data.list'))->toHaveCount(1);

    $opClose = $this->postJson('/api/admin/payments/'.$payment->id.'/close', [], $this->operatorAuth);
    expect($opClose->json('code'))->toBe(40003);

    $adminClose = $this->postJson('/api/admin/payments/'.$payment->id.'/close', ['reason' => '测试关闭'], $this->adminAuth);
    expect($adminClose->json('code'))->toBe(0)
        ->and($adminClose->json('data.status'))->toBe('closed');
});

// 列表筛选 + 汇总统计
test('TC-PAY-002 列表支持渠道/状态/订单号筛选并回传统计', function () {
    [$orderA, $noA] = preparePayment($this, 'wechat');
    [$orderB, $noB, $amountB] = preparePayment($this, 'alipay');

    // B 单完成支付（沙箱回调）
    $this->postJson('/api/payments/sandbox/'.$noB)->assertOk();
    expect(Payment::where('payment_no', $noB)->value('status'))->toBe(Payment::STATUS_SUCCESS);

    $all = $this->getJson('/api/admin/payments', $this->adminAuth)->json('data');
    expect($all['list'])->toHaveCount(2)
        ->and($all['summary']['total'])->toBe(2)
        ->and($all['summary']['success_count'])->toBe(1)
        ->and($all['summary']['pending_count'])->toBe(1)
        ->and($all['summary']['success_amount'])->toBe($amountB);

    $byChannel = $this->getJson('/api/admin/payments?channel=alipay', $this->adminAuth)->json('data');
    expect($byChannel['list'])->toHaveCount(1)
        ->and($byChannel['list'][0]['channel_label'])->toBe('支付宝');

    $byStatus = $this->getJson('/api/admin/payments?status=pending', $this->adminAuth)->json('data');
    expect($byStatus['list'])->toHaveCount(1)
        ->and($byStatus['list'][0]['payment_no'])->toBe($noA);

    $byOrderNo = $this->getJson('/api/admin/payments?order_no='.$orderB['order_no'], $this->adminAuth)->json('data');
    expect($byOrderNo['list'])->toHaveCount(1)
        ->and($byOrderNo['list'][0]['order_no'])->toBe($orderB['order_no']);
});

// 详情：支付单信息 + 关联订单 + 支付日志时间轴
test('TC-PAY-003 详情含订单摘要与支付日志时间轴', function () {
    [$order, $paymentNo] = preparePayment($this);
    $this->postJson('/api/payments/sandbox/'.$paymentNo)->assertOk();

    $payment = Payment::where('payment_no', $paymentNo)->firstOrFail();
    $detail = $this->getJson('/api/admin/payments/'.$payment->id, $this->adminAuth)->json('data');

    expect($detail['order']['order_no'])->toBe($order['order_no'])
        ->and($detail['order']['status'])->toBe(Order::STATUS_PAID)
        ->and($detail['status_label'])->toBe('支付成功')
        ->and($detail['channel_trade_no'])->not->toBeNull();

    $events = collect($detail['logs'])->pluck('event')->all();
    expect($events)->toContain(PaymentLog::EVENT_CREATE)
        ->and($events)->toContain(PaymentLog::EVENT_CALLBACK);

    // 不存在的支付单
    $this->getJson('/api/admin/payments/999999', $this->adminAuth)->assertJsonPath('code', 40004);
});

// 关闭：状态流转 + payment_logs 留痕 + sys_operation_log 留痕
test('TC-PAY-004 关闭支付单写入支付日志与操作日志', function () {
    [$order, $paymentNo] = preparePayment($this);
    $payment = Payment::where('payment_no', $paymentNo)->firstOrFail();

    $resp = $this->postJson('/api/admin/payments/'.$payment->id.'/close', ['reason' => '重复下单'], $this->adminAuth);
    expect($resp->json('code'))->toBe(0)
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_CLOSED);

    $this->assertDatabaseHas('payment_logs', [
        'payment_id' => $payment->id,
        'event' => PaymentLog::EVENT_CLOSE,
    ]);

    $opLog = SysOperationLog::where('module', 'payment')->where('action', 'close')->latest('id')->first();
    expect($opLog)->not->toBeNull()
        ->and($opLog->target_type)->toBe('payment')
        ->and($opLog->content)->toContain('重复下单');
});

// 边界：非 pending 拒绝 40009；已关闭重复关闭 40009；不存在 40004
test('TC-PAY-005 关闭边界：非待支付 40009 / 不存在 40004', function () {
    [$order, $paymentNo] = preparePayment($this);
    $this->postJson('/api/payments/sandbox/'.$paymentNo)->assertOk();
    $payment = Payment::where('payment_no', $paymentNo)->firstOrFail();

    $resp = $this->postJson('/api/admin/payments/'.$payment->id.'/close', [], $this->adminAuth);
    expect($resp->json('code'))->toBe(40009);

    $this->postJson('/api/admin/payments/999999/close', [], $this->adminAuth)
        ->assertJsonPath('code', 40004);
});

// 支付日志列表与详情：摘要截断 + 完整 JSON
test('TC-PAY-006 支付日志列表摘要与详情完整 JSON', function () {
    [$order, $paymentNo, $amount] = preparePayment($this);

    $list = $this->getJson('/api/admin/payment-logs?payment_no='.$paymentNo, $this->adminAuth)->json('data');
    expect($list['list'])->not->toBeEmpty()
        ->and($list['list'][0]['event_label'])->toBe('创建支付单')
        ->and($list['list'][0]['request_preview'])->toContain('channel');

    $logId = $list['list'][0]['id'];
    $detail = $this->getJson('/api/admin/payment-logs/'.$logId, $this->adminAuth)->json('data');
    expect($detail['request_data']['channel'])->toBe('wechat')
        ->and($detail['request_data']['amount'])->toBe($amount);

    $this->getJson('/api/admin/payment-logs/999999', $this->adminAuth)->assertJsonPath('code', 40004);
});

// 导出 CSV
test('TC-PAY-007 支付单导出 CSV', function () {
    [$order, $paymentNo] = preparePayment($this);

    $response = $this->getJson('/api/admin/payments/export', $this->adminAuth);
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $content = $response->streamedContent();
    expect($content)->toContain('支付单号')
        ->and($content)->toContain($paymentNo);
});
