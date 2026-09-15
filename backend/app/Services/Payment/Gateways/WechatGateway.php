<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\Dto\CallbackResult;
use App\Services\Payment\Dto\PayParams;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Dto\RefundResult;
use App\Services\Payment\Dto\TestResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * 微信支付 V3 —— Native 扫码（收银台方案 §6.2 / §8.4）
 *
 * 实现范围：Native 下单、回调验签 + AES-256-GCM 解密、主动查单、申请退款、证书连通性自检。
 *
 * 注意：微信支付 V3 没有官方沙箱环境（V2 sandboxnew 已下线），
 * 沙箱模式一律由 MockGateway 代理，本网关只在正式配置下启用。
 */
class WechatGateway implements PaymentGateway
{
    private const BASE_URI = 'https://api.mch.weixin.qq.com';

    /** 微信支付状态 → 内部支付状态 */
    private const TRADE_STATE_MAP = [
        'SUCCESS' => Payment::STATUS_SUCCESS,
        'REFUND' => Payment::STATUS_SUCCESS,
        'NOTPAY' => Payment::STATUS_PENDING,
        'USERPAYING' => Payment::STATUS_PENDING,
        'CLOSED' => Payment::STATUS_CLOSED,
        'REVOKED' => Payment::STATUS_CLOSED,
        'PAYERROR' => Payment::STATUS_FAILED,
    ];

    public function channel(): string
    {
        return Payment::CHANNEL_WECHAT;
    }

    /**
     * Native 下单：POST /v3/pay/transactions/native
     *
     * @param  array  $context  {order?: Order, recharge?: BalanceRecharge}
     */
    public function create(Payment $payment, array $context, array $config): PayParams
    {
        $body = [
            'appid' => (string) ($config['app_id'] ?? ''),
            'mchid' => (string) ($config['mch_id'] ?? ''),
            'description' => $this->subject($payment, $context),
            'out_trade_no' => $payment->payment_no,
            'notify_url' => (string) ($config['notify_url'] ?? ''),
            'amount' => [
                'total' => (int) bcmul((string) $payment->amount, '100', 0),
                'currency' => 'CNY',
            ],
        ];

        if (! empty($config['sub_mch_id'])) {
            $body['sub_mchid'] = (string) $config['sub_mch_id'];
        }

        $response = $this->request('POST', '/v3/pay/transactions/native', $body, $config);

        $codeUrl = (string) ($response['code_url'] ?? '');
        if ($codeUrl === '') {
            throw new \RuntimeException('微信下单失败：未返回 code_url');
        }

        return PayParams::qrcode($codeUrl, now()->addMinutes(30)->format('Y-m-d H:i:s'));
    }

    /**
     * 回调验签（V3）：校验请求头签名 → AES-256-GCM 解密 resource
     */
    public function verifyCallback(Request $request, array $config): CallbackResult
    {
        $body = (string) $request->getContent();
        $timestamp = (string) $request->header('Wechatpay-Timestamp', '');
        $nonce = (string) $request->header('Wechatpay-Nonce', '');
        $signature = (string) $request->header('Wechatpay-Signature', '');
        $serial = (string) $request->header('Wechatpay-Serial', '');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return CallbackResult::fail('缺少微信验签请求头');
        }

        if (! $this->verifySign($timestamp, $nonce, $body, $signature, (string) ($config['wechatpay_public_key'] ?? ''))) {
            return CallbackResult::fail('微信回调验签失败');
        }

        $payload = json_decode($body, true) ?: [];
        $resource = $payload['resource'] ?? [];

        try {
            $decrypted = $this->decryptResource($resource, (string) ($config['api_v3_key'] ?? ''));
        } catch (Throwable $e) {
            return CallbackResult::fail('微信回调解密失败：'.$e->getMessage());
        }

        $tradeState = (string) ($decrypted['trade_state'] ?? 'SUCCESS');

        return CallbackResult::success(
            (string) ($decrypted['out_trade_no'] ?? ''),
            (string) ($decrypted['transaction_id'] ?? ''),
            isset($decrypted['amount']['total'])
                ? bcdiv((string) $decrypted['amount']['total'], '100', 2)
                : '',
            self::TRADE_STATE_MAP[$tradeState] ?? Payment::STATUS_FAILED,
            $decrypted,
        );
    }

    /**
     * 主动查单：GET /v3/pay/transactions/out-trade-no/{no}
     */
    public function query(Payment $payment, array $config): QueryResult
    {
        $path = '/v3/pay/transactions/out-trade-no/'.$payment->payment_no
            .'?mchid='.urlencode((string) ($config['mch_id'] ?? ''));

        $response = $this->request('GET', $path, null, $config);

        $state = (string) ($response['trade_state'] ?? '');

        return QueryResult::success(
            self::TRADE_STATE_MAP[$state] ?? Payment::STATUS_PENDING,
            (string) ($response['transaction_id'] ?? ''),
            isset($response['amount']['total']) ? bcdiv((string) $response['amount']['total'], '100', 2) : null,
            $response,
        );
    }

    /**
     * 申请退款：POST /v3/refund/domestic/refunds
     */
    public function refund(Payment $payment, string $amount, string $reason, array $config): RefundResult
    {
        $body = [
            'out_trade_no' => $payment->payment_no,
            'out_refund_no' => 'R'.Str::upper((string) Str::ulid()),
            'reason' => $reason,
            'amount' => [
                'refund' => (int) bcmul($amount, '100', 0),
                'total' => (int) bcmul((string) $payment->amount, '100', 0),
                'currency' => 'CNY',
            ],
        ];

        $response = $this->request('POST', '/v3/refund/domestic/refunds', $body, $config);

        return RefundResult::success((string) ($response['out_refund_no'] ?? ''), $response);
    }

    /** 连通性自检：GET /v3/certificates（微信无沙箱，沙箱模式下不调用） */
    public function testConnection(array $config): TestResult
    {
        try {
            $response = $this->request('GET', '/v3/certificates', null, $config);
        } catch (Throwable $e) {
            return TestResult::fail('微信连接失败：'.$e->getMessage());
        }

        return TestResult::ok('微信支付连接正常，已获取到平台证书', [
            'cert_count' => count($response['data'] ?? []),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function subject(Payment $payment, array $context): string
    {
        $title = '订单支付 '.$payment->payment_no;
        if ($payment->isRecharge()) {
            return '余额充值 '.$payment->biz_no;
        }

        return $title;
    }

    /**
     * 发起 V3 签名请求
     */
    private function request(string $method, string $path, ?array $body, array $config): array
    {
        $payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        $mchId = (string) ($config['mch_id'] ?? '');
        $serial = (string) ($config['merchant_cert_serial_no'] ?? '');
        $privateKey = (string) ($config['merchant_private_key'] ?? '');

        $timestamp = (string) time();
        $nonce = Str::random(32);
        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $mchId,
            $nonce,
            $this->sign($method, $path, $timestamp, $nonce, $payload, $privateKey),
            $timestamp,
            $serial,
        );

        $request = Http::baseUrl(self::BASE_URI)
            ->withHeaders([
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'CubeShop/1.2',
            ])
            ->timeout(15);

        $response = $body === null
            ? $request->get($path)
            : $request->withBody($payload, 'application/json')->send($method, $path);

        if (! $response->successful()) {
            throw new \RuntimeException('微信接口返回 '.$response->status().'：'.$response->body());
        }

        return $response->json() ?: [];
    }

    /** V3 请求签名：SHA256-RSA2048(method\npath\ntimestamp\nnonce\nbody\n) */
    public function sign(string $method, string $path, string $timestamp, string $nonce, string $body, string $privateKey): string
    {
        $message = implode("\n", [$method, $path, $timestamp, $nonce, $body])."\n";

        openssl_sign($message, $rawSign, $this->normalizePrivateKey($privateKey), 'sha256WithRSAEncryption');

        return base64_encode($rawSign);
    }

    /** 回调验签 */
    private function verifySign(string $timestamp, string $nonce, string $body, string $signature, string $publicKey): bool
    {
        if ($publicKey === '') {
            return false;
        }

        $message = implode("\n", [$timestamp, $nonce, $body])."\n";

        return openssl_verify($message, base64_decode($signature), $this->normalizePublicKey($publicKey), 'sha256WithRSAEncryption') === 1;
    }

    /** AES-256-GCM 解密 resource */
    public function decryptResource(array $resource, string $apiV3Key): array
    {
        $ciphertext = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
        if ($ciphertext === false || strlen($ciphertext) < 16) {
            throw new \RuntimeException('ciphertext 非法');
        }

        $tag = substr($ciphertext, -16);
        $data = substr($ciphertext, 0, -16);

        $plain = openssl_decrypt(
            $data,
            'aes-256-gcm',
            $apiV3Key,
            OPENSSL_RAW_DATA,
            (string) ($resource['nonce'] ?? ''),
            $tag,
            (string) ($resource['associated_data'] ?? ''),
        );

        if ($plain === false) {
            throw new \RuntimeException('openssl_decrypt 失败');
        }

        return json_decode($plain, true) ?: [];
    }

    /** 私钥 PEM 兼容（可能是纯 base64 或无换行） */
    private function normalizePrivateKey(string $key): string
    {
        if (str_contains($key, 'PRIVATE KEY')) {
            return $key;
        }

        return "-----BEGIN RSA PRIVATE KEY-----\n".chunk_split($key, 64, "\n")."-----END RSA PRIVATE KEY-----\n";
    }

    /** 公钥 PEM 兼容（微信支付公钥可能是纯 base64） */
    private function normalizePublicKey(string $key): string
    {
        if (str_contains($key, 'PUBLIC KEY')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
