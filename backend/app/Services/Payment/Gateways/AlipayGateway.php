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
 * 支付宝 PC 网站支付（RSA2，收银台方案 §6.2 / §8.3）
 *
 * 实现范围：alipay.trade.page.pay（自动提交表单）、回调验签、主动查单、退款、连通性自检。
 * 沙箱：gateway 指向 openapi-sandbox.dl.alipaydev.com 时即为支付宝官方沙箱环境。
 */
class AlipayGateway implements PaymentGateway
{
    public const GATEWAY_PROD = 'https://openapi.alipay.com/gateway.do';
    public const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    /** 支付宝交易状态 → 内部支付状态 */
    private const TRADE_STATUS_MAP = [
        'WAIT_BUYER_PAY' => Payment::STATUS_PENDING,
        'TRADE_SUCCESS' => Payment::STATUS_SUCCESS,
        'TRADE_FINISHED' => Payment::STATUS_SUCCESS,
        'TRADE_CLOSED' => Payment::STATUS_CLOSED,
    ];

    public function channel(): string
    {
        return Payment::CHANNEL_ALIPAY;
    }

    /**
     * PC 网站支付：返回自动提交表单（前端直接渲染跳转）
     */
    public function create(Payment $payment, array $context, array $config): PayParams
    {
        $params = $this->commonParams('alipay.trade.page.pay', $config);
        $params['biz_content'] = json_encode([
            'out_trade_no' => $payment->payment_no,
            'total_amount' => (string) $payment->amount,
            'subject' => $payment->isRecharge() ? '余额充值 '.$payment->biz_no : '订单支付 '.$payment->payment_no,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
        ], JSON_UNESCAPED_UNICODE);

        $params['sign'] = $this->sign($this->buildSignContent($params), (string) ($config['private_key'] ?? ''));

        return PayParams::form($this->buildForm($params, $this->gateway($config)));
    }

    /**
     * 回调验签：RSA2 验签 + 校验 app_id 与金额
     */
    public function verifyCallback(Request $request, array $config): CallbackResult
    {
        $params = $request->request->all();
        $sign = (string) ($params['sign'] ?? '');

        if ($sign === '' || ! $this->verify($this->buildSignContent($params), $sign, (string) ($config['alipay_public_key'] ?? ''))) {
            return CallbackResult::fail('支付宝回调验签失败');
        }

        // 二次校验：收款 app_id 必须一致，防止他单回调混入
        $appId = (string) ($params['app_id'] ?? '');
        if ($appId !== '' && $appId !== (string) ($config['app_id'] ?? '')) {
            return CallbackResult::fail('支付宝回调 app_id 不匹配');
        }

        $tradeStatus = (string) ($params['trade_status'] ?? '');
        $status = self::TRADE_STATUS_MAP[$tradeStatus] ?? Payment::STATUS_FAILED;

        return CallbackResult::success(
            (string) ($params['out_trade_no'] ?? ''),
            (string) ($params['trade_no'] ?? ''),
            (string) ($params['total_amount'] ?? ''),
            $status,
            $params,
        );
    }

    /** 主动查单：alipay.trade.query */
    public function query(Payment $payment, array $config): QueryResult
    {
        $params = $this->commonParams('alipay.trade.query', $config);
        $params['biz_content'] = json_encode([
            'out_trade_no' => $payment->payment_no,
        ], JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->sign($this->buildSignContent($params), (string) ($config['private_key'] ?? ''));

        $response = $this->post($params, $config);
        $node = $response['alipay_trade_query_response'] ?? [];

        if (($node['code'] ?? '10000') !== '10000') {
            return QueryResult::fail((string) ($node['sub_msg'] ?? $node['msg'] ?? '查询失败'), $node);
        }

        return QueryResult::success(
            self::TRADE_STATUS_MAP[(string) ($node['trade_status'] ?? '')] ?? Payment::STATUS_PENDING,
            (string) ($node['trade_no'] ?? ''),
            (string) ($node['total_amount'] ?? ''),
            $node,
        );
    }

    /** 退款：alipay.trade.refund */
    public function refund(Payment $payment, string $amount, string $reason, array $config): RefundResult
    {
        $params = $this->commonParams('alipay.trade.refund', $config);
        $params['biz_content'] = json_encode([
            'out_trade_no' => $payment->payment_no,
            'refund_amount' => $amount,
            'refund_reason' => $reason,
            'out_request_no' => 'R'.Str::upper((string) Str::ulid()),
        ], JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->sign($this->buildSignContent($params), (string) ($config['private_key'] ?? ''));

        $response = $this->post($params, $config);
        $node = $response['alipay_trade_refund_response'] ?? [];

        if (($node['code'] ?? '10000') !== '10000') {
            return RefundResult::fail((string) ($node['sub_msg'] ?? $node['msg'] ?? '退款失败'), $node);
        }

        return RefundResult::success((string) ($node['trade_no'] ?? ''), $node);
    }

    /**
     * 连通性自检：查一笔必然不存在的单号
     * 返回「交易不存在」即说明网关与密钥均有效；签名错误会返回 40002。
     */
    public function testConnection(array $config): TestResult
    {
        $params = $this->commonParams('alipay.trade.query', $config);
        $params['biz_content'] = json_encode(['out_trade_no' => 'CUBESHOP-TEST-'.time()], JSON_UNESCAPED_UNICODE);
        $params['sign'] = $this->sign($this->buildSignContent($params), (string) ($config['private_key'] ?? ''));

        try {
            $response = $this->post($params, $config);
        } catch (Throwable $e) {
            return TestResult::fail('支付宝连接失败：'.$e->getMessage());
        }

        $node = $response['alipay_trade_query_response'] ?? [];
        $subCode = (string) ($node['sub_code'] ?? '');

        if (($node['code'] ?? '') === '40004' || $subCode === 'ACQ.TRADE_NOT_EXIST') {
            return TestResult::ok('支付宝连接正常（密钥校验通过）', ['gateway' => $this->gateway($config)]);
        }

        if (($node['code'] ?? '10000') !== '10000') {
            return TestResult::fail('支付宝返回异常：'.($node['sub_msg'] ?? $node['msg'] ?? '未知错误'), $node);
        }

        return TestResult::ok('支付宝连接正常', ['gateway' => $this->gateway($config)]);
    }

    /* ------------------------------------------------------------------ */

    public function gateway(array $config): string
    {
        $gateway = (string) ($config['gateway'] ?? '');
        if ($gateway !== '') {
            return $gateway;
        }

        return ($config['sandbox'] ?? false) ? self::GATEWAY_SANDBOX : self::GATEWAY_PROD;
    }

    private function commonParams(string $method, array $config): array
    {
        return [
            'app_id' => (string) ($config['app_id'] ?? ''),
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => (string) ($config['sign_type'] ?? 'RSA2'),
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => (string) ($config['notify_url'] ?? ''),
            'return_url' => (string) ($config['return_url'] ?? ''),
        ];
    }

    /** 待签串：参数按 key 升序，k=v 用 & 连接，排除 sign / sign_type 与空值 */
    public function buildSignContent(array $params): string
    {
        $filtered = array_filter(
            $params,
            fn ($value, $key) => $key !== 'sign' && $key !== 'sign_type' && $value !== '' && $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
        ksort($filtered);

        $parts = [];
        foreach ($filtered as $key => $value) {
            $parts[] = $key.'='.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        return implode('&', $parts);
    }

    /** RSA2 签名 */
    public function sign(string $content, string $privateKey): string
    {
        $key = str_contains($privateKey, 'PRIVATE KEY')
            ? $privateKey
            : "-----BEGIN RSA PRIVATE KEY-----\n".chunk_split($privateKey, 64, "\n")."-----END RSA PRIVATE KEY-----\n";

        openssl_sign($content, $rawSign, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($rawSign);
    }

    /** RSA2 验签 */
    public function verify(string $content, string $signature, string $publicKey): bool
    {
        if ($publicKey === '' || $signature === '') {
            return false;
        }

        $key = str_contains($publicKey, 'PUBLIC KEY')
            ? $publicKey
            : "-----BEGIN PUBLIC KEY-----\n".chunk_split($publicKey, 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_verify($content, base64_decode($signature), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** 自动提交表单 */
    private function buildForm(array $params, string $gateway): string
    {
        $inputs = '';
        foreach ($params as $key => $value) {
            $inputs .= sprintf('<input type="hidden" name="%s" value="%s" />', $key, htmlspecialchars((string) $value, ENT_QUOTES));
        }

        return sprintf(
            '<form id="alipaysubmit" name="alipaysubmit" action="%s?charset=utf-8" method="POST">%s</form><script>document.forms["alipaysubmit"].submit();</script>',
            htmlspecialchars($gateway, ENT_QUOTES),
            $inputs,
        );
    }

    /**
     * POST 网关并校验响应签名
     */
    private function post(array $params, array $config): array
    {
        $response = Http::asForm()->timeout(15)->post($this->gateway($config), $params);

        if (! $response->successful()) {
            throw new \RuntimeException('支付宝接口返回 '.$response->status());
        }

        $raw = (string) $response->body();
        $json = json_decode($raw, true) ?: [];

        // 响应验签：取 response 节点原始片段（业界通用做法）
        $node = null;
        foreach (array_keys($json) as $key) {
            if (str_ends_with((string) $key, '_response')) {
                $node = (string) $key;
                break;
            }
        }

        if ($node !== null && isset($json['sign'])) {
            $start = strpos($raw, '"'.$node.'":');
            $end = strrpos($raw, ',"sign":');
            if ($start !== false && $end !== false && $end > $start) {
                $content = substr($raw, $start + strlen('"'.$node.'":'), $end - $start - strlen('"'.$node.'":'));
                if (! $this->verify($content, (string) $json['sign'], (string) ($config['alipay_public_key'] ?? ''))) {
                    throw new \RuntimeException('支付宝响应验签失败');
                }
            }
        }

        return $json;
    }
}
