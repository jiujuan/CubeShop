<?php

use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Services\Payment\Gateways\AlipayGateway;
use App\Services\Payment\Gateways\BalanceGateway;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\OfflineGateway;
use App\Services\Payment\Gateways\WechatGateway;
use App\Services\Payment\PaymentChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Phase 2 单元测试：退款网关层（查单 / 幂等单号 / 微信退款回调验签）
 *
 * 全部用 Http::fake 拦截外部请求，密钥对在测试内生成，不依赖真实商户号。
 */

function refundTestKeyPair(): array
{
    static $pair = null;
    if ($pair !== null) {
        return $pair;
    }

    $args = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $configPath = null;

    foreach ([
        getenv('OPENSSL_CONF'),
        dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
        dirname(PHP_BINARY).'/ssl/openssl.cnf',
    ] as $candidate) {
        if ($candidate && is_file($candidate)) {
            $configPath = $candidate;
            $args['config'] = $candidate;
            break;
        }
    }

    $res = openssl_pkey_new($args);
    if ($res === false) {
        throw new \RuntimeException('无法生成 RSA 密钥对：openssl_pkey_new 失败（缺少 openssl.cnf）');
    }

    $exported = $configPath !== null
        ? openssl_pkey_export($res, $privateKey, null, ['config' => $configPath])
        : openssl_pkey_export($res, $privateKey);

    if ($exported === false || ! is_string($privateKey)) {
        throw new \RuntimeException('无法导出 RSA 私钥：openssl_pkey_export 失败（缺少 openssl.cnf）');
    }

    $publicKey = openssl_pkey_get_details($res)['key'];

    return $pair = [$privateKey, $publicKey];
}

// ---------------------------------------------------------------- 支付宝

test('支付宝 refund 用编排层传入的 out_request_no 且金额/单号正确', function () {
    [$privateKey, $publicKey] = refundTestKeyPair();

    Http::fake([
        'openapi.alipay.com/gateway.do' => function ($request) {
            parse_str((string) $request->body(), $form);
            $biz = json_decode($form['biz_content'], true);

            expect($biz['out_request_no'])->toBe('R-ORCHESTRATED-001')
                ->and($biz['refund_amount'])->toBe('10.00')
                ->and($biz['out_trade_no'])->toBe('PAY-ALI-R1');

            return Http::response([
                'alipay_trade_refund_response' => [
                    'code' => '10000', 'msg' => 'Success',
                    'trade_no' => '2026091622001', 'out_request_no' => $biz['out_request_no'],
                ],
            ]);
        },
    ]);

    $payment = new Payment(['payment_no' => 'PAY-ALI-R1', 'channel' => 'alipay', 'amount' => '10.00']);
    $gateway = new AlipayGateway();

    $result = $gateway->refund(
        $payment, '10.00', '测试退款',
        ['app_id' => '2021000000000001', 'private_key' => $privateKey, 'alipay_public_key' => $publicKey],
        'R-ORCHESTRATED-001',
    );

    expect($result->ok)->toBeTrue()
        ->and($result->refundNo)->toBe('2026091622001')
        ->and($result->channelStatus)->toBeNull();
});

test('支付宝 queryRefund 映射 refund_status 到 channelStatus', function () {
    [$privateKey, $publicKey] = refundTestKeyPair();

    Http::fake([
        'openapi.alipay.com/gateway.do' => Http::response([
            'alipay_trade_fastpay_refund_query_response' => [
                'code' => '10000', 'msg' => 'Success',
                'out_request_no' => 'R-ORCHESTRATED-001', 'refund_status' => 'REFUND_SUCCESS',
            ],
        ]),
    ]);

    $payment = new Payment(['payment_no' => 'PAY-ALI-R1', 'channel' => 'alipay', 'amount' => '10.00']);
    $result = (new AlipayGateway())->queryRefund(
        $payment, 'R-ORCHESTRATED-001',
        ['app_id' => '2021000000000001', 'private_key' => $privateKey, 'alipay_public_key' => $publicKey],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->channelStatus)->toBe('SUCCESS')
        ->and($result->refundNo)->toBe('R-ORCHESTRATED-001');
});

// ---------------------------------------------------------------- 微信

test('微信 refund 回填 PROCESSING 且请求含 out_refund_no 与分为单位的金额', function () {
    [$privateKey, $publicKey] = refundTestKeyPair();
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    Http::fake([
        'api.mch.weixin.qq.com/v3/refund/domestic/refunds' => function ($request) {
            $body = json_decode((string) $request->body(), true);

            expect($body['out_refund_no'])->toBe('R-WX-ORCH-001')
                ->and($body['amount']['refund'])->toBe(1300)   // 13.00 元 → 1300 分
                ->and($body['amount']['total'])->toBe(1300);

            return Http::response(['out_refund_no' => 'R-WX-ORCH-001', 'refund_id' => 'RF001', 'status' => 'PROCESSING']);
        },
    ]);

    $payment = new Payment(['payment_no' => 'PAY-WX-R1', 'channel' => 'wechat', 'amount' => '13.00']);
    $config = [
        'app_id' => 'wx-appid', 'mch_id' => '1900000001', 'api_v3_key' => $apiV3Key,
        'merchant_private_key' => $privateKey, 'merchant_cert_serial_no' => 'S1', 'wechatpay_public_key' => $publicKey,
    ];

    $result = (new WechatGateway())->refund($payment, '13.00', '测试退款', $config, 'R-WX-ORCH-001');

    expect($result->ok)->toBeTrue()
        ->and($result->refundNo)->toBe('R-WX-ORCH-001')
        ->and($result->channelStatus)->toBe('PROCESSING');
});

test('微信 queryRefund 映射 status 到 channelStatus', function () {
    [$privateKey, $publicKey] = refundTestKeyPair();
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    Http::fake([
        'api.mch.weixin.qq.com/v3/refund/domestic/refunds/*' => Http::response([
            'refund_id' => 'RF002', 'status' => 'SUCCESS', 'out_refund_no' => 'R-WX-ORCH-002',
        ]),
    ]);

    $payment = new Payment(['payment_no' => 'PAY-WX-R2', 'channel' => 'wechat', 'amount' => '20.00']);
    $config = [
        'app_id' => 'wx-appid', 'mch_id' => '1900000001', 'api_v3_key' => $apiV3Key,
        'merchant_private_key' => $privateKey, 'merchant_cert_serial_no' => 'S1', 'wechatpay_public_key' => $publicKey,
    ];

    $result = (new WechatGateway())->queryRefund($payment, 'R-WX-ORCH-002', $config);

    expect($result->ok)->toBeTrue()
        ->and($result->channelStatus)->toBe('SUCCESS')
        ->and($result->refundNo)->toBe('RF002');
});

test('微信 verifyRefundCallback 验签并解出 out_refund_no 与 channelStatus', function () {
    [$privateKey, $publicKey] = refundTestKeyPair();
    $apiV3Key = '0123456789abcdef0123456789abcdef';
    $gateway = new WechatGateway();

    $plain = json_encode([
        'out_trade_no' => 'PAY-WX-R3',
        'out_refund_no' => 'R-WX-ORCH-003',
        'refund_id' => 'RF003',
        'refund_status' => 'SUCCESS',
        'success_time' => '2026-09-26T10:00:00+08:00',
    ], JSON_UNESCAPED_UNICODE);

    $nonce = 'abcdefghijkl';
    $aad = 'encrypt-resource';
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

    $body = json_encode([
        'event_type' => 'REFUND.SUCCESS',
        'resource' => [
            'ciphertext' => base64_encode($cipher.$tag),
            'nonce' => $nonce,
            'associated_data' => $aad,
            'algorithm' => 'AEAD_AES_256_GCM',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $timestamp = (string) time();
    $nonceHeader = 'nonce-refund-123';
    openssl_sign(implode("\n", [$timestamp, $nonceHeader, $body])."\n", $rawSign, openssl_pkey_get_private($privateKey), 'sha256WithRSAEncryption');

    $request = Request::create('/api/payments/callback/wechat', 'POST', [], [], [], [
        'HTTP_Wechatpay-Timestamp' => $timestamp,
        'HTTP_Wechatpay-Nonce' => $nonceHeader,
        'HTTP_Wechatpay-Signature' => base64_encode($rawSign),
        'HTTP_Wechatpay-Serial' => 'SERIAL123',
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $result = $gateway->verifyRefundCallback($request, [
        'api_v3_key' => $apiV3Key,
        'wechatpay_public_key' => $publicKey,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->outRefundNo)->toBe('R-WX-ORCH-003')
        ->and($result->channelStatus)->toBe('SUCCESS')
        ->and($result->eventType)->toBe('REFUND.SUCCESS');
});

test('微信 verifyRefundCallback 验签失败返回 ok=false', function () {
    [$privateKey, $publicKey] = refundTestKeyPair();
    $gateway = new WechatGateway();

    $body = json_encode(['resource' => ['ciphertext' => 'x']], JSON_UNESCAPED_UNICODE);
    $timestamp = (string) time();

    $request = Request::create('/api/payments/callback/wechat', 'POST', [], [], [], [
        'HTTP_Wechatpay-Timestamp' => $timestamp,
        'HTTP_Wechatpay-Nonce' => 'nonce123',
        'HTTP_Wechatpay-Signature' => base64_encode('bad-signature'),
        'HTTP_Wechatpay-Serial' => 'SERIAL123',
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $result = $gateway->verifyRefundCallback($request, [
        'api_v3_key' => '0123456789abcdef0123456789abcdef',
        'wechatpay_public_key' => $publicKey,
    ]);

    expect($result->ok)->toBeFalse();
});

// ---------------------------------------------------------------- 余额 / Mock / Offline 兜底

test('余额 / Mock / Offline 的 queryRefund 均返回 unsupported', function () {
    $payment = new Payment(['payment_no' => 'PAY-X', 'channel' => 'balance', 'amount' => '5.00']);

    $balance = (new BalanceGateway(app(\App\Services\Payment\BalanceService::class)))->queryRefund($payment, 'R1', []);
    $mock = (new MockGateway())->queryRefund($payment, 'R1', []);
    $offline = (new OfflineGateway(app(PaymentChannelService::class)))->queryRefund($payment, 'R1', []);

    expect($balance->ok)->toBeFalse()->and($balance->channelStatus)->toBe('unsupported')
        ->and($mock->ok)->toBeFalse()->and($mock->channelStatus)->toBe('unsupported')
        ->and($offline->ok)->toBeFalse()->and($offline->channelStatus)->toBe('unsupported');
});

test('余额 refund 成功且忽略 outRefundNo（同步即时到账，单号用余额流水 id）', function () {
    $user = createTestUser();
    $payment = new Payment(['payment_no' => 'PAY-BAL', 'channel' => 'balance', 'amount' => '5.00', 'user_id' => $user->id]);
    $result = (new BalanceGateway(app(\App\Services\Payment\BalanceService::class)))
        ->refund($payment, '5.00', '退款', [], 'R-BAL-001');

    // 余额退款同步完成：ok 成功，单号取余额流水 id（outRefundNo 由异步渠道使用）
    expect($result->ok)->toBeTrue()->and($result->refundNo)->not->toBeEmpty();
});

test('Mock refund 透传编排层传入的 outRefundNo', function () {
    $payment = new Payment(['payment_no' => 'PAY-MOCK', 'channel' => 'mock', 'amount' => '5.00']);
    $result = (new MockGateway())->refund($payment, '5.00', '退款', [], 'R-MOCK-001');

    expect($result->ok)->toBeTrue()->and($result->refundNo)->toBe('R-MOCK-001');
});
