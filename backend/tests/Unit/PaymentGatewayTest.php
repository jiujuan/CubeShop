<?php

use App\Exceptions\BusinessException;
use App\Models\BalanceRecharge;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\PaymentLog;
use App\Models\SysUser;
use App\Models\UserBalanceLog;
use App\Services\Payment\BalanceService;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Gateways\BalanceGateway;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\OfflineGateway;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/**
 * P1+P2 单元测试：渠道适配层与余额账户（收银台方案 §8.5）
 */

/** 建一个待支付订单（复用 PaymentServiceTest 的构造） */
function pendingOrderForGateway(int $qty = 1, string $price = '100.00'): array
{
    $user = createTestUser();
    $sku = createTestSku(stock: 20, price: $price);
    \App\Models\CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => $qty]);
    $address = \App\Models\UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '路 1 号',
    ]);
    $order = app(\App\Services\Order\OrderService::class)->createFromCart($user->id, $address->id, null, null);

    return [$user, $sku, $order];
}

// ---------------------------------------------------------------- Mock 网关

test('Mock 网关签名正确报文可验签通过', function () {
    $gateway = new MockGateway('wechat');
    $sign = MockGateway::sign('PAY001', 'TRADE1', '10.00', Payment::STATUS_SUCCESS);

    $request = Request::create('/', 'POST', [
        'payment_no' => 'PAY001',
        'channel_trade_no' => 'TRADE1',
        'amount' => '10.00',
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $sign,
    ]);

    $result = $gateway->verifyCallback($request, []);

    expect($result->ok)->toBeTrue()
        ->and($result->paymentNo)->toBe('PAY001')
        ->and($result->amount)->toBe('10.00');
});

test('Mock 网关篡改报文验签失败', function () {
    $gateway = new MockGateway();
    $sign = MockGateway::sign('PAY001', 'TRADE1', '10.00', Payment::STATUS_SUCCESS);

    $request = Request::create('/', 'POST', [
        'payment_no' => 'PAY001',
        'channel_trade_no' => 'TRADE1',
        'amount' => '0.01', // 篡改金额
        'status' => Payment::STATUS_SUCCESS,
        'sign' => $sign,
    ]);

    expect($gateway->verifyCallback($request, [])->ok)->toBeFalse();
});

test('Mock 网关 create 返回 mock 类型且兼容沙箱字段', function () {
    $payment = new Payment(['payment_no' => 'PAY-X', 'channel' => 'wechat', 'amount' => '10.00']);
    $params = (new MockGateway('wechat'))->create($payment, [], []);

    expect($params->type)->toBe(PayParams::TYPE_MOCK)
        ->and($params->toArray())->toHaveKey('sandbox_pay_url')
        ->and($params->toArray()['mode'])->toBe('sandbox');
});

// ---------------------------------------------------------------- 余额网关

test('余额支付：余额充足时扣减并写流水', function () {
    $user = createTestUser();
    app(BalanceService::class)->credit($user->id, '150.00', UserBalanceLog::TYPE_ADMIN_ADJUST, null, null, '初始');

    $payment = Payment::create([
        'payment_no' => 'PAY-BAL-1', 'user_id' => $user->id, 'channel' => Payment::CHANNEL_BALANCE,
        'amount' => '100.00', 'status' => Payment::STATUS_PENDING,
    ]);

    $params = (new BalanceGateway(app(BalanceService::class)))->create($payment, [], []);

    expect($params->type)->toBe(PayParams::TYPE_DIRECT)
        ->and(app(BalanceService::class)->balance($user->id))->toBe('50.00');

    $log = UserBalanceLog::where('user_id', $user->id)->where('type', UserBalanceLog::TYPE_CONSUME)->first();
    expect($log->amount)->toBe('-100.00')
        ->and($log->balance_before)->toBe('150.00')
        ->and($log->balance_after)->toBe('50.00');
});

test('余额支付：余额不足拒绝扣款（40010）', function () {
    $user = createTestUser();
    app(BalanceService::class)->credit($user->id, '10.00');

    $payment = Payment::create([
        'payment_no' => 'PAY-BAL-2', 'user_id' => $user->id, 'channel' => Payment::CHANNEL_BALANCE,
        'amount' => '100.00', 'status' => Payment::STATUS_PENDING,
    ]);

    (new BalanceGateway(app(BalanceService::class)))->create($payment, [], []);
})->throws(BusinessException::class, '余额不足');

test('余额支付并发两次只成功一次（不超扣）', function () {
    $user = createTestUser();
    app(BalanceService::class)->credit($user->id, '100.00');

    $service = app(BalanceService::class);

    // 两次扣 100：第二次应被余额校验拦截
    $service->debit($user->id, '100.00', UserBalanceLog::TYPE_CONSUME, 'test', 1);

    expect($service->balance($user->id))->toBe('0.00');

    try {
        $service->debit($user->id, '100.00', UserBalanceLog::TYPE_CONSUME, 'test', 2);
        $this->fail('余额不足应拒绝');
    } catch (BusinessException $e) {
        expect($e->businessCode)->toBe(40000);
    }

    expect($service->balance($user->id))->toBe('0.00')
        ->and(UserBalanceLog::where('user_id', $user->id)->where('type', UserBalanceLog::TYPE_CONSUME)->count())->toBe(1);
});

// ---------------------------------------------------------------- 充值入账

test('充值入账只加一次（回调重发幂等）', function () {
    $user = createTestUser();
    $recharge = BalanceRecharge::create([
        'recharge_no' => 'RC20260916000001', 'user_id' => $user->id,
        'amount' => '100.00', 'gift_amount' => '10.00', 'channel' => Payment::CHANNEL_WECHAT,
        'status' => BalanceRecharge::STATUS_PENDING,
    ]);

    $service = app(BalanceService::class);
    $service->creditForRecharge($recharge);
    $service->creditForRecharge($recharge);
    $service->creditForRecharge($recharge);

    expect($service->balance($user->id))->toBe('110.00')
        ->and(UserBalanceLog::where('related_type', 'recharge')->where('related_id', $recharge->id)->count())->toBe(1);

    $log = UserBalanceLog::first();
    expect($log->amount)->toBe('110.00')
        ->and($log->balance_before)->toBe('0.00')
        ->and($log->balance_after)->toBe('110.00');
});

// ---------------------------------------------------------------- 渠道配置

test('渠道配置：敏感键加密落库且可解密回读', function () {
    $service = app(PaymentChannelService::class);
    $service->ensurePresets();

    $saved = $service->buildConfigForSave('wechat', [
        'app_id' => 'wx1234567890',
        'mch_id' => '1900000001',
        'api_v3_key' => 'my-secret-v3-key-9999',
    ]);

    $channel = PaymentChannel::where('channel', 'wechat')->first();
    $channel->forceFill(['config' => $saved['config']])->save();

    // 库里不落明文
    expect($channel->fresh()->config['api_v3_key'])->not->toBe('my-secret-v3-key-9999')
        ->and($channel->fresh()->config['app_id'])->toBe('wx1234567890');

    // 解密回读与掩码
    expect($service->decryptedConfig('wechat')['api_v3_key'])->toBe('my-secret-v3-key-9999')
        ->and($service->maskedConfig('wechat')['api_v3_key'])->toBe('****9999')
        ->and($service->maskedConfig('wechat')['has_api_v3_key'])->toBeTrue()
        ->and($saved['changed'])->toContain('api_v3_key');
});

test('渠道配置：敏感键留空或掩码不覆盖原值', function () {
    $service = app(PaymentChannelService::class);
    $service->ensurePresets();

    $channel = PaymentChannel::where('channel', 'wechat')->first();
    $channel->forceFill(['config' => $service->buildConfigForSave('wechat', ['api_v3_key' => 'origin-key-1111'])['config']])->save();

    // 提交掩码 → 保持原值
    $saved = $service->buildConfigForSave('wechat', ['app_id' => 'wx-new', 'api_v3_key' => '****1111']);
    $channel->forceFill(['config' => $saved['config']])->save();

    expect($service->decryptedConfig('wechat')['api_v3_key'])->toBe('origin-key-1111')
        ->and($service->decryptedConfig('wechat')['app_id'])->toBe('wx-new')
        ->and($saved['changed'])->toBe(['app_id']);
});

test('渠道启停影响可用性判定', function () {
    $service = app(PaymentChannelService::class);
    $service->ensurePresets();

    expect($service->isEnabled('wechat'))->toBeTrue();

    PaymentChannel::where('channel', 'wechat')->update(['enabled' => false]);
    expect($service->isEnabled('wechat'))->toBeFalse();

    // 未启用渠道发起支付被拒
    $service = app(PaymentService::class);
    [$user, $sku, $order] = pendingOrderForGateway();
    $service->createPayment($order, $user->id, 'wechat');
})->throws(BusinessException::class, '支付渠道未启用');

// ---------------------------------------------------------------- 线下转账

test('线下转账：缺凭证字段拒绝发起', function () {
    $gateway = new OfflineGateway(app(PaymentChannelService::class));
    $payment = new Payment(['payment_no' => 'PAY-OFF', 'amount' => '10.00']);

    $gateway->create($payment, ['extra' => ['payer_name' => '张三']], []);
})->throws(BusinessException::class, '请填写转账流水号');

test('线下转账：支付单置为待核账且订单不流转', function () {
    [$user, $sku, $order] = pendingOrderForGateway();

    [$payment] = app(PaymentService::class)->createPayment($order, $user->id, Payment::CHANNEL_OFFLINE, [
        'payer_name' => '张三',
        'payer_account' => '6222 **** 1234',
        'transfer_no' => 'TR20260916001',
        'transferred_at' => '2026-09-16 10:30:00',
        'voucher_url' => '/storage/vouchers/1/a.png',
    ]);

    expect($payment->status)->toBe(Payment::STATUS_REVIEWING)
        ->and($payment->transfer_no)->toBe('TR20260916001')
        ->and($order->fresh()->status)->toBe(Order::STATUS_PENDING_PAYMENT);
});

test('线下转账：核账通过驱动订单支付成功，驳回回到失败', function () {
    [$user, $sku, $order] = pendingOrderForGateway();
    $service = app(PaymentService::class);

    $extra = ['payer_name' => '张三', 'transfer_no' => 'TR001', 'voucher_url' => '/storage/v/1.png'];

    [$payment] = $service->createPayment($order, $user->id, Payment::CHANNEL_OFFLINE, $extra);

    // 驳回（必填原因）
    $service->review($payment->fresh(), 1, false, '凭证不清晰');
    expect($payment->fresh()->status)->toBe(Payment::STATUS_FAILED)
        ->and($payment->fresh()->review_remark)->toBe('凭证不清晰')
        ->and($order->fresh()->status)->toBe(Order::STATUS_PENDING_PAYMENT);

    // 重新提交 → 再次进入待核账 → 通过
    [$payment2] = $service->createPayment($order->fresh(), $user->id, Payment::CHANNEL_OFFLINE, $extra);
    expect($payment2->id)->toBe($payment->id)
        ->and($payment2->status)->toBe(Payment::STATUS_REVIEWING);

    $reviewed = $service->review($payment2, 1, true, '核对无误');

    expect($reviewed->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PENDING_SHIP)
        ->and(PaymentLog::where('event', PaymentLog::EVENT_REVIEW)->count())->toBe(2);
});

test('核账驳回未填原因被拒绝', function () {
    [$user, $sku, $order] = pendingOrderForGateway();
    $service = app(PaymentService::class);

    [$payment] = $service->createPayment($order, $user->id, Payment::CHANNEL_OFFLINE, [
        'payer_name' => '张三', 'transfer_no' => 'TR001', 'voucher_url' => '/storage/v/1.png',
    ]);

    $service->review($payment, 1, false, '  ');
})->throws(BusinessException::class, '驳回时请填写原因');

// ---------------------------------------------------------------- 渠道切换

test('切换支付渠道复用同一支付单而非新建', function () {
    [$user, $sku, $order] = pendingOrderForGateway();
    $service = app(PaymentService::class);

    [$p1] = $service->createPayment($order, $user->id, 'wechat');
    [$p2] = $service->createPayment($order, $user->id, 'alipay');

    expect($p2->id)->toBe($p1->id)
        ->and($p2->channel)->toBe('alipay')
        ->and(Payment::where('order_id', $order->id)->count())->toBe(1);
});
