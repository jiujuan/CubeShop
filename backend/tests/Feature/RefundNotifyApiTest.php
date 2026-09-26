<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Refund;
use App\Models\RefundLog;
use App\Services\Refund\RefundLogger;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/**
 * Phase 4 功能测试：退款异步通知回调（免 sanctum，微信验签）
 *
 * 覆盖：微信 SUCCESS/ABNORMAL 回调落库、非微信渠道忽略、验签失败忽略、重复推送幂等。
 * 测试内密钥对在本地生成，不依赖真实商户号。
 */

function phase4RefundKeyPair(): array
{
    static $pair = null;
    if ($pair !== null) {
        return $pair;
    }

    $args = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $configPath = null;
    foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', dirname(PHP_BINARY).'/ssl/openssl.cnf'] as $candidate) {
        if ($candidate && is_file($candidate)) {
            $configPath = $candidate;
            $args['config'] = $candidate;
            break;
        }
    }

    $res = openssl_pkey_new($args);
    if ($res === false) {
        throw new \RuntimeException('无法生成 RSA 密钥对：openssl_pkey_new 失败');
    }

    $exported = $configPath !== null
        ? openssl_pkey_export($res, $privateKey, null, ['config' => $configPath])
        : openssl_pkey_export($res, $privateKey);

    if ($exported === false || ! is_string($privateKey)) {
        throw new \RuntimeException('无法导出 RSA 私钥');
    }

    $publicKey = openssl_pkey_get_details($res)['key'];

    return $pair = [$privateKey, $publicKey];
}

function phase4CreateRefundingFixture(): array
{
    $user = createTestUser();
    $sku = createTestSku(stock: 20, price: '100.00');
    \App\Models\CartItem::create(['user_id' => $user->id, 'sku_id' => $sku->id, 'quantity' => 1]);
    $address = \App\Models\UserAddress::create([
        'user_id' => $user->id,
        'contact_name' => 'a', 'contact_phone' => 'b',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ]);
    $service = app(OrderService::class);
    $order = $service->createFromCart($user->id, $address->id, null, null);
    $order = $service->transitionTo($order, Order::STATUS_PAID);
    // 订单进入退款中（markRefundSuccess 终态流转目标）
    $order->update(['status' => Order::STATUS_REFUNDING]);

    return [$user, $sku, $order];
}

function phase4ConfigureWechatChannel(string $privateKey, string $publicKey): void
{
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    // 测试环境沙箱默认开启 → 工厂会降级 MockGateway；此处关闭沙箱并补全商户参数，
    // 让 PaymentGatewayFactory::make('wechat') 返回真实 WechatGateway，走完整验签/解密路径。
    PaymentChannel::updateOrCreate(['channel' => 'wechat'], [
        'name' => '微信支付',
        'enabled' => true,
        'sandbox' => false,
        'sort' => 10,
        'config' => [
            'app_id' => 'wx-appid',
            'mch_id' => '1900000001',
            'api_v3_key' => Crypt::encryptString($apiV3Key),
            'merchant_private_key' => Crypt::encryptString($privateKey),
            'merchant_cert_serial_no' => 'S1',
            'wechatpay_public_key' => Crypt::encryptString($publicKey),
        ],
    ]);
}

function phase4SignRefundCallback(string $body, string $privateKey): array
{
    $timestamp = (string) time();
    $nonceHeader = 'nonce-refund-'.bin2hex(random_bytes(6));
    openssl_sign(implode("\n", [$timestamp, $nonceHeader, $body])."\n", $rawSign, openssl_pkey_get_private($privateKey), 'sha256WithRSAEncryption');

    return [
        'HTTP_Wechatpay-Timestamp' => $timestamp,
        'HTTP_Wechatpay-Nonce' => $nonceHeader,
        'HTTP_Wechatpay-Signature' => base64_encode($rawSign),
        'HTTP_Wechatpay-Serial' => 'SERIAL123',
        'CONTENT_TYPE' => 'application/json',
    ];
}

function phase4BuildCallbackBody(string $outRefundNo, string $refundStatus, string $eventType, string $apiV3Key, string $refundId = 'RF-AUTO'): string
{
    $plain = json_encode([
        'out_trade_no' => 'PAY-WX-CB',
        'out_refund_no' => $outRefundNo,
        'refund_id' => $refundId,
        'refund_status' => $refundStatus,
        'success_time' => '2026-09-26T10:00:00+08:00',
    ], JSON_UNESCAPED_UNICODE);

    $nonce = 'abcdefghijkl';
    $aad = 'encrypt-resource';
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

    return json_encode([
        'event_type' => $eventType,
        'resource' => [
            'ciphertext' => base64_encode($cipher.$tag),
            'nonce' => $nonce,
            'associated_data' => $aad,
            'algorithm' => 'AEAD_AES_256_GCM',
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function phase4MakeRefund(string $outRefundNo, string $channel, string $status): Refund
{
    [$user, $sku, $order] = phase4CreateRefundingFixture();

    return Refund::create([
        'refund_no' => 'RF'.strtoupper(\Illuminate\Support\Str::random(12)),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'type' => Refund::TYPE_REFUND,
        'amount' => '10.00',
        'status' => $status,
        'channel' => $channel,
        'payment_no' => 'PAY-WX-CB',
        'out_refund_no' => $outRefundNo,
    ]);
}

// ---------------------------------------------------------------- 微信 SUCCESS

test('微信退款回调 SUCCESS 落 success 并写 channel_callback 日志', function () {
    [$privateKey, $publicKey] = phase4RefundKeyPair();
    phase4ConfigureWechatChannel($privateKey, $publicKey);
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    $refund = phase4MakeRefund('R-CB-SUC-001', Payment::CHANNEL_WECHAT, Refund::STATUS_PROCESSING);
    $orderId = $refund->order_id;

    $body = phase4BuildCallbackBody('R-CB-SUC-001', 'SUCCESS', 'REFUND.SUCCESS', $apiV3Key);
    $server = phase4SignRefundCallback($body, $privateKey);

    $response = $this->call('POST', '/api/payments/wechat/refund-notify', [], [], [], $server, $body);

    $response->assertStatus(200);
    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and($refund->refund_status)->toBe('SUCCESS')
        ->and($refund->channel_refund_no)->toBe('RF-AUTO')
        ->and(Order::whereKey($orderId)->value('status'))->toBe(Order::STATUS_REFUNDED);
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_CHANNEL_CALLBACK)->exists())->toBeTrue();
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_SUCCESS)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- 微信 ABNORMAL

test('微信退款回调 ABNORMAL 落 failed 并写 channel_callback + failed 日志', function () {
    [$privateKey, $publicKey] = phase4RefundKeyPair();
    phase4ConfigureWechatChannel($privateKey, $publicKey);
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    $refund = phase4MakeRefund('R-CB-ABN-001', Payment::CHANNEL_WECHAT, Refund::STATUS_PROCESSING);
    $orderId = $refund->order_id;

    $body = phase4BuildCallbackBody('R-CB-ABN-001', 'ABNORMAL', 'REFUND.ABNORMAL', $apiV3Key, 'RF-ABN-1');
    $server = phase4SignRefundCallback($body, $privateKey);

    $response = $this->call('POST', '/api/payments/wechat/refund-notify', [], [], [], $server, $body);

    $response->assertStatus(200);
    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_FAILED)
        ->and($refund->refund_status)->toBe('ABNORMAL')
        ->and($refund->failed_reason)->toContain('ABNORMAL')
        // 订单保持退款中，转人工处理
        ->and(Order::whereKey($orderId)->value('status'))->toBe(Order::STATUS_REFUNDING);
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_CHANNEL_CALLBACK)->exists())->toBeTrue();
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_FAILED)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- 非微信渠道

test('非微信渠道回调直接 ACK 不处理', function () {
    $refund = phase4MakeRefund('R-CB-ALI-001', Payment::CHANNEL_ALIPAY, Refund::STATUS_PROCESSING);

    $response = $this->call('POST', '/api/payments/alipay/refund-notify', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

    $response->assertStatus(200);
    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_PROCESSING);
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_CHANNEL_CALLBACK)->exists())->toBeFalse();
});

// ---------------------------------------------------------------- 验签失败

test('微信验签失败直接 ACK 不落状态', function () {
    [$privateKey, $publicKey] = phase4RefundKeyPair();
    phase4ConfigureWechatChannel($privateKey, $publicKey);

    $refund = phase4MakeRefund('R-CB-VF-001', Payment::CHANNEL_WECHAT, Refund::STATUS_PROCESSING);

    $body = json_encode(['resource' => ['ciphertext' => 'x']], JSON_UNESCAPED_UNICODE);
    $server = [
        'HTTP_Wechatpay-Timestamp' => (string) time(),
        'HTTP_Wechatpay-Nonce' => 'nonce123',
        'HTTP_Wechatpay-Signature' => base64_encode('bad-signature'),
        'HTTP_Wechatpay-Serial' => 'SERIAL123',
        'CONTENT_TYPE' => 'application/json',
    ];

    $response = $this->call('POST', '/api/payments/wechat/refund-notify', [], [], [], $server, $body);

    $response->assertStatus(200);
    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_PROCESSING);
    expect(RefundLog::where('refund_id', $refund->id)->where('type', RefundLogger::TYPE_CHANNEL_CALLBACK)->exists())->toBeFalse();
});

// ---------------------------------------------------------------- 幂等

test('重复推送同一 SUCCESS 回调幂等不报错', function () {
    [$privateKey, $publicKey] = phase4RefundKeyPair();
    phase4ConfigureWechatChannel($privateKey, $publicKey);
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    $refund = phase4MakeRefund('R-CB-IDM-001', Payment::CHANNEL_WECHAT, Refund::STATUS_PROCESSING);
    $orderId = $refund->order_id;

    $body = phase4BuildCallbackBody('R-CB-IDM-001', 'SUCCESS', 'REFUND.SUCCESS', $apiV3Key);
    $server = phase4SignRefundCallback($body, $privateKey);

    $this->call('POST', '/api/payments/wechat/refund-notify', [], [], [], $server, $body)->assertStatus(200);
    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS);

    // 第二次重放：终态退款单直接跳过，不再重复流转
    $this->call('POST', '/api/payments/wechat/refund-notify', [], [], [], $server, $body)->assertStatus(200);
    $refund->refresh();
    expect($refund->status)->toBe(Refund::STATUS_SUCCESS)
        ->and(Order::whereKey($orderId)->value('status'))->toBe(Order::STATUS_REFUNDED);
});
