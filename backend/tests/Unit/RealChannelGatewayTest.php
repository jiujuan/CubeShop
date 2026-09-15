<?php

use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Gateways\AlipayGateway;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\Gateways\WechatGateway;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * P3 单元测试：真实渠道网关（微信 V3 Native / 支付宝 RSA2）
 *
 * 全部用 Http::fake 拦截外部请求，密钥对在测试内生成，不依赖真实商户号。
 */

/**
 * 生成一把测试用 RSA 密钥对（进程内缓存，避免重复生成拖慢测试）
 *
 * Windows 下 openssl_pkey_new / openssl_pkey_export 都需要显式指定 openssl.cnf，
 * 否则 export 会静默失败并返回 null 私钥，导致后续验签全部失败。
 */
function rsaKeyPair(): array
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

/** 写入渠道配置（敏感键自动加密） */
function seedChannelConfig(string $channel, array $config, bool $sandbox = false, bool $enabled = true): void
{
    $service = app(PaymentChannelService::class);
    $service->ensurePresets();

    $record = PaymentChannel::where('channel', $channel)->first();
    $record->forceFill([
        'config' => $service->buildConfigForSave($channel, $config)['config'],
        'sandbox' => $sandbox,
        'enabled' => $enabled,
    ])->save();
}

// ---------------------------------------------------------------- 微信 V3

test('微信 Native 下单返回二维码参数', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
    seedChannelConfig('wechat', [
        'app_id' => 'wx-appid',
        'mch_id' => '1900000001',
        'api_v3_key' => '0123456789abcdef0123456789abcdef',
        'merchant_private_key' => $privateKey,
        'merchant_cert_serial_no' => 'SERIAL123',
        'wechatpay_public_key' => $publicKey,
    ]);

    Http::fake([
        'api.mch.weixin.qq.com/*' => Http::response(['code_url' => 'weixin://wxpay/bizpayurl?pr=TEST123'], 200),
    ]);

    $payment = new Payment([
        'payment_no' => 'PAY-WX-1', 'channel' => 'wechat', 'amount' => '12.34',
        'biz_type' => Payment::BIZ_TYPE_ORDER, 'biz_no' => 'SO001',
    ]);

    $gateway = app(PaymentGatewayFactory::class)->make('wechat');
    expect($gateway)->toBeInstanceOf(WechatGateway::class);

    $params = $gateway->create($payment, [], app(PaymentChannelService::class)->decryptedConfig('wechat'));

    expect($params->type)->toBe(PayParams::TYPE_QRCODE)
        ->and($params->toArray()['code_url'])->toBe('weixin://wxpay/bizpayurl?pr=TEST123');

    // 下单金额单位为分
    Http::assertSent(function ($request) {
        $body = json_decode((string) $request->body(), true);

        return $body['amount']['total'] === 1234 && $body['out_trade_no'] === 'PAY-WX-1';
    });
});

test('微信回调验签与 resource 解密', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
    $apiV3Key = '0123456789abcdef0123456789abcdef';

    $gateway = new WechatGateway();

    // 构造加密 resource（模拟微信服务端）
    $plain = json_encode([
        'out_trade_no' => 'PAY-WX-2',
        'transaction_id' => '4200000001',
        'trade_state' => 'SUCCESS',
        'amount' => ['total' => 5000, 'currency' => 'CNY'],
    ], JSON_UNESCAPED_UNICODE);

    $nonce = 'abcdefghijkl';
    $aad = 'encrypt-resource';
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

    $body = json_encode([
        'resource' => [
            'ciphertext' => base64_encode($cipher.$tag),
            'nonce' => $nonce,
            'associated_data' => $aad,
            'algorithm' => 'AEAD_AES_256_GCM',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $timestamp = (string) time();
    $nonceHeader = 'nonce123';

    // 平台侧用商户私钥签（示例），网关用 wechatpay_public_key（= 商户公钥）验
    openssl_sign(implode("\n", [$timestamp, $nonceHeader, $body])."\n", $rawSign, openssl_pkey_get_private($privateKey), 'sha256WithRSAEncryption');

    $request = Request::create('/api/payments/callback/wechat', 'POST', [], [], [], [
        'HTTP_Wechatpay-Timestamp' => $timestamp,
        'HTTP_Wechatpay-Nonce' => $nonceHeader,
        'HTTP_Wechatpay-Signature' => base64_encode($rawSign),
        'HTTP_Wechatpay-Serial' => 'SERIAL123',
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    // 验签公钥需与签名私钥配对 → 这里用商户密钥对的公钥
    $result = $gateway->verifyCallback($request, [
        'api_v3_key' => $apiV3Key,
        'wechatpay_public_key' => $publicKey,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->paymentNo)->toBe('PAY-WX-2')
        ->and($result->amount)->toBe('50.00')
        ->and($result->status)->toBe(Payment::STATUS_SUCCESS);
});

test('微信回调篡改报文验签失败', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
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

    $result = $gateway->verifyCallback($request, ['api_v3_key' => '0123456789abcdef0123456789abcdef', 'wechatpay_public_key' => $publicKey]);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('微信回调验签失败');
});

test('微信主动查单映射交易状态', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
    seedChannelConfig('wechat', [
        'app_id' => 'wx-appid', 'mch_id' => '1900000001',
        'api_v3_key' => '0123456789abcdef0123456789abcdef',
        'merchant_private_key' => $privateKey, 'merchant_cert_serial_no' => 'S1',
        'wechatpay_public_key' => $publicKey,
    ]);

    Http::fake([
        'api.mch.weixin.qq.com/v3/pay/transactions/out-trade-no/*' => Http::response([
            'trade_state' => 'SUCCESS', 'transaction_id' => '420000001',
            'amount' => ['total' => 8800],
        ], 200),
    ]);

    $payment = new Payment(['payment_no' => 'PAY-WX-3', 'channel' => 'wechat', 'amount' => '88.00']);
    $result = (new WechatGateway())->query($payment, app(PaymentChannelService::class)->decryptedConfig('wechat'));

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($result->amount)->toBe('88.00');
});

// ---------------------------------------------------------------- 支付宝

test('支付宝下单返回自动提交表单且含签名', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
    seedChannelConfig('alipay', [
        'app_id' => '2021000000000001',
        'private_key' => $privateKey,
        'alipay_public_key' => $publicKey,
    ]);

    $payment = new Payment([
        'payment_no' => 'PAY-ALI-1', 'channel' => 'alipay', 'amount' => '66.60',
        'biz_type' => Payment::BIZ_TYPE_ORDER, 'biz_no' => 'SO002',
    ]);

    $gateway = app(PaymentGatewayFactory::class)->make('alipay');
    expect($gateway)->toBeInstanceOf(AlipayGateway::class);

    $params = $gateway->create($payment, [], app(PaymentChannelService::class)->decryptedConfig('alipay'));

    expect($params->type)->toBe(PayParams::TYPE_FORM)
        ->and($params->toArray()['form_html'])->toContain('alipaysubmit')
        ->and($params->toArray()['form_html'])->toContain('name="sign"');
});

test('支付宝回调验签通过且金额与单号正确', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
    $gateway = new AlipayGateway();

    $params = [
        'app_id' => '2021000000000001',
        'out_trade_no' => 'PAY-ALI-2',
        'trade_no' => '2026091622001',
        'total_amount' => '88.80',
        'trade_status' => 'TRADE_SUCCESS',
        'sign_type' => 'RSA2',
    ];
    $params['sign'] = $gateway->sign($gateway->buildSignContent($params), $privateKey);

    $request = Request::create('/api/payments/callback/alipay', 'POST', $params);
    $result = $gateway->verifyCallback($request, [
        'app_id' => '2021000000000001',
        'alipay_public_key' => $publicKey,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->paymentNo)->toBe('PAY-ALI-2')
        ->and($result->amount)->toBe('88.80')
        ->and($result->status)->toBe(Payment::STATUS_SUCCESS);
});

test('支付宝回调签名被篡改或 app_id 不符均拒绝', function () {
    [$privateKey, $publicKey] = rsaKeyPair();
    $gateway = new AlipayGateway();

    $params = [
        'app_id' => '2021000000000001', 'out_trade_no' => 'PAY-ALI-3',
        'total_amount' => '10.00', 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'RSA2',
    ];
    $params['sign'] = $gateway->sign($gateway->buildSignContent($params), $privateKey);
    $params['total_amount'] = '0.01'; // 篡改金额

    $result = $gateway->verifyCallback(
        Request::create('/api/payments/callback/alipay', 'POST', $params),
        ['app_id' => '2021000000000001', 'alipay_public_key' => $publicKey],
    );
    expect($result->ok)->toBeFalse();

    // app_id 不匹配
    $params2 = [
        'app_id' => '9999', 'out_trade_no' => 'PAY-ALI-3',
        'total_amount' => '10.00', 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'RSA2',
    ];
    $params2['sign'] = $gateway->sign($gateway->buildSignContent($params2), $privateKey);

    $result2 = $gateway->verifyCallback(
        Request::create('/api/payments/callback/alipay', 'POST', $params2),
        ['app_id' => '2021000000000001', 'alipay_public_key' => $publicKey],
    );
    expect($result2->ok)->toBeFalse()
        ->and($result2->message)->toBe('支付宝回调 app_id 不匹配');
});

test('支付宝沙箱模式指向沙箱网关', function () {
    $gateway = new AlipayGateway();

    expect($gateway->gateway(['sandbox' => true]))->toBe(AlipayGateway::GATEWAY_SANDBOX)
        ->and($gateway->gateway(['sandbox' => false]))->toBe(AlipayGateway::GATEWAY_PROD)
        ->and($gateway->gateway(['gateway' => 'https://custom/gateway.do']))->toBe('https://custom/gateway.do');
});

// ---------------------------------------------------------------- 工厂路由

test('沙箱或未配置时降级为 Mock 网关', function () {
    $factory = app(PaymentGatewayFactory::class);

    // 默认（无配置 + 沙箱）→ Mock
    expect($factory->make('wechat'))->toBeInstanceOf(MockGateway::class)
        ->and($factory->make('alipay'))->toBeInstanceOf(MockGateway::class);

    // 微信：关闭沙箱但配置不全 → 仍降级 Mock（微信无沙箱，配置必须齐全）
    app(PaymentChannelService::class)->ensurePresets();
    PaymentChannel::where('channel', 'wechat')->update(['sandbox' => false]);

    expect($factory->make('wechat'))->toBeInstanceOf(MockGateway::class);
});

test('不支持的渠道由工厂直接拒绝', function () {
    app(PaymentGatewayFactory::class)->make('bitcoin');
})->throws(App\Exceptions\BusinessException::class, '不支持的支付渠道');
