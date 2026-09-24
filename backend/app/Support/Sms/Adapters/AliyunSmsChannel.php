<?php

namespace App\Support\Sms\Adapters;

use App\Support\Sms\AliyunV3Signer;
use App\Support\Sms\Dto\SmsResult;
use App\Support\Sms\SmsChannel;
use App\Support\Sms\SmsProvider;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 阿里云短信适配器（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D4
 *
 * 自写 HTTP + V3 签名（ACS3-HMAC-SHA256），不引官方 SDK：签名只有几十行，
 * 而 SDK 会拖进一堆传递依赖。风险（签名算错）由 `AliyunV3Signer` 的官方示例向量测试覆盖。
 *
 * 三点容易踩错、这里逐一固定下来：
 * 1. **业务参数放 query string，Action/Version 放 `x-acs-*` 头**（官方 API 文档的自签名示例即如此）；
 * 2. **请求体为空**，`x-acs-content-sha256` 传 `sha256('')`。
 *    ⚠️ 不能用 `Http::post($url)`：Laravel 默认会发 `[]` 并带上 `Content-Type: application/json`，
 *    签名里没有 content-type，网关会直接判签名不一致。必须显式 `send('POST', $url, ['body' => ''])`；
 * 3. HMAC 密钥是 AccessKey Secret 本身（详见 `AliyunV3Signer` 类注释）。
 *
 * 适配器不抛异常：HTTP 异常、超时、JSON 解析失败一律转 `SmsResult::fail()`。
 */
final class AliyunSmsChannel implements SmsChannel
{
    public const ACTION = 'SendSms';

    public const VERSION = '2017-05-25';

    /** 接入地址固定（中国站），不随 region 变化 */
    public const ENDPOINT = 'https://dysmsapi.aliyuncs.com/';

    public const HOST = 'dysmsapi.aliyuncs.com';

    /**
     * 常见错误码 → 运维看得懂的中文提示
     *
     * 未列出的错误码直接回原始 Code + Message，保证排障信息不丢失。
     *
     * @var array<string, string>
     */
    private const ERROR_MESSAGES = [
        'isv.BUSINESS_LIMIT_CONTROL' => '触发阿里云流控（同手机号发送过于频繁），请稍后再试',
        'isv.AMOUNT_NOT_ENOUGH' => '短信账户余额不足，请充值',
        'isv.SMS_SIGNATURE_ILLEGAL' => '短信签名不合法或未审核通过',
        'isv.SMS_TEMPLATE_ILLEGAL' => '短信模板不合法或未审核通过',
        'isv.MOBILE_NUMBER_ILLEGAL' => '手机号格式不合法',
        'isv.TEMPLATE_MISSING_PARAMETERS' => '模板变量与模板定义不匹配',
        'isv.INVALID_PARAMETERS' => '参数不合法',
        'InvalidAccessKeyId.NotFound' => 'AccessKey ID 不存在或已禁用',
        'SignatureDoesNotMatch' => '签名校验失败（AccessKey Secret 与 ID 不匹配）',
        'Forbidden.RAM' => 'RAM 子账号无短信服务权限',
    ];

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $accessKeySecret,
        private readonly string $signName,
    ) {
    }

    public function send(string $phone, string $templateCode, array $params): SmsResult
    {
        if (! $this->available()) {
            return SmsResult::fail('missing_credentials', '阿里云短信凭证或签名未配置完整');
        }

        $started = microtime(true);

        $query = [
            'PhoneNumbers' => $phone,
            'SignName' => $this->signName,
            'TemplateCode' => $templateCode,
            // 中文模板变量不做 \uXXXX 转义（项目踩过中文转义坑，统一 UNESCAPED_UNICODE）
            'TemplateParam' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        $payloadHash = hash('sha256', '');
        $signedHeaders = [
            'x-acs-action' => self::ACTION,
            'x-acs-version' => self::VERSION,
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce' => bin2hex(random_bytes(16)),
            'x-acs-content-sha256' => $payloadHash,
        ];

        $headers = $signedHeaders + [
            'Authorization' => AliyunV3Signer::authorization(
                $this->accessKeyId,
                $this->accessKeySecret,
                'POST',
                self::HOST,
                $query,
                $signedHeaders,
                $payloadHash,
            ),
        ];

        $timeout = max(1, (int) config('services.sms.timeout', 5));

        try {
            // ⚠️ 必须显式空 body：见类注释第 2 点
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->connectTimeout(min(3, $timeout))
                ->send('POST', self::ENDPOINT.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), ['body' => '']);
        } catch (Throwable $e) {
            return SmsResult::fail('http_error', '阿里云短信请求异常：'.$e->getMessage(), [], self::elapsed($started));
        }

        $latency = self::elapsed($started);
        $body = $response->json();

        if (! is_array($body)) {
            return SmsResult::fail(
                'invalid_response',
                '阿里云短信响应解析失败（HTTP '.$response->status().'）',
                ['status' => $response->status()],
                $latency,
            );
        }

        if (! $response->successful()) {
            return SmsResult::fail(
                (string) ($body['Code'] ?? 'http_error'),
                '阿里云短信 HTTP '.$response->status().'：'.($body['Message'] ?? ''),
                $body,
                $latency,
            );
        }

        $code = (string) ($body['Code'] ?? '');

        if ($code === 'OK') {
            return SmsResult::ok($body['BizId'] ?? null, $body, $latency);
        }

        return SmsResult::fail($code, $this->messageFor($code, $body['Message'] ?? null), $body, $latency);
    }

    public function available(): bool
    {
        return $this->accessKeyId !== ''
            && $this->accessKeySecret !== ''
            && $this->signName !== '';
    }

    public function provider(): string
    {
        return SmsProvider::ALIYUN;
    }

    private function messageFor(string $code, ?string $rawMessage): string
    {
        $mapped = self::ERROR_MESSAGES[$code] ?? null;

        if ($mapped === null) {
            return $rawMessage !== null && $rawMessage !== ''
                ? $rawMessage
                : '短信发送失败（'.$code.'）';
        }

        return $mapped;
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
