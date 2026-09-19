<?php

namespace App\Services\Wms\Adapters\Cainiao;

use App\Exceptions\BusinessException;
use App\Exceptions\Wms\WmsGatewayException;
use App\Models\WmsConfig;
use App\Services\Wms\Dto\WmsResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 奇门 HTTP 网关（WMS 计划 P2 / F3、Step 3）
 *
 * 只负责「把一次业务调用发出去、把回执原样带回来」：
 * 组装系统参数 → 签名 → POST（业务报文原文放 body）→ 解析回执信封。
 * **不做业务解释**——`flag=failure` 该不该重试、是不是幂等成功，由
 * {@see CainiaoErrorCode} 与 {@see \App\Services\Wms\Adapters\CainiaoAdapter} 判定。
 *
 * 三个刻意的取舍：
 * 1. **写接口不做 HTTP 层重试**（`retry(0)`）。奇门创建接口虽按 `deliveryOrderCode` 幂等，
 *    但盲目重发会放大对方压力；重试统一交给 `PushOutboundJob`（有次数上限、退避与人工兜底）。
 * 2. **配置缺失 fail-closed**：未配网关地址 / 缺 AppKey·AppSecret 时抛 `BusinessException`，
 *    而不是降级成 Mock 或空跑——生产环境绝不允许「看起来成功」的假象（SEC-01）。
 *    这类错误重试无意义，故不走 `WmsGatewayException`（那个是可重试语义）。
 * 3. **签名用的 body 与实际发出的 body 逐字节一致**：用 `withBody()` 发送而不是把数组交给
 *    HTTP 客户端自行 json_encode——否则中文转义/斜杠转义差异会让签名对不上。
 */
class CainiaoGateway
{
    public function __construct(private readonly Signature $signature) {}

    /**
     * 发起一次奇门调用。
     *
     * @param  string  $method  奇门方法名（如 `taobao.qimen.deliveryorder.create`）
     * @param  array<string, mixed>  $bizContent  业务报文（会被 JSON 序列化后放入 body）
     * @param  string|null  $requestId  幂等/追踪用；随回执一并返回
     * @return array{
     *     http_status: int, request_id: string|null, duration_ms: int,
     *     flag: string|null, code: string|null, message: string|null,
     *     payload: array<string, mixed>, body: string,
     *     request: array<string, mixed>
     * }
     *
     * @throws BusinessException 配置缺失（fail-closed）
     * @throws WmsGatewayException 网络/超时/非 2xx/报文不可解析（可重试语义）
     */
    public function post(string $method, array $bizContent, WmsConfig $config, ?string $requestId = null): array
    {
        $url = $this->gatewayUrl($config);
        $secret = $this->assertCredentials($config);

        $body = (string) json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $params = $this->systemParams($method, $config);
        $params['sign'] = $this->signature->sign(
            $params,
            $secret,
            $body,
            $this->signMethod(),
            $this->signWrap(),
        );

        // 签名用原始值（未 URL 编码），传输时才编码——与官方示例一致
        $endpoint = $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);

        $startedAt = microtime(true);

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->post($endpoint);
        } catch (ConnectionException $e) {
            throw new WmsGatewayException(
                '网络异常，未能连上菜鸟网关：'.$e->getMessage(),
                null,
                null,
                retryable: true,
            );
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $raw = $response->body();
        $status = $response->status();

        // 非 2xx：HTTP 层就失败了，回执内容可能不是 JSON，原样带上备查
        if (! $response->successful()) {
            throw new WmsGatewayException(
                "菜鸟网关返回 HTTP {$status}",
                $status,
                $raw,
                retryable: CainiaoErrorCode::isRetryableHttp($status),
            );
        }

        $json = json_decode($raw, true);
        if (! is_array($json)) {
            throw new WmsGatewayException(
                '菜鸟网关回执不是合法 JSON',
                $status,
                mb_substr($raw, 0, 500),
                retryable: true,
            );
        }

        $envelope = $this->extractEnvelope($json);

        return [
            'http_status' => $status,
            'request_id' => $requestId,
            'duration_ms' => $duration,
            'flag' => $envelope['flag'],
            'code' => $envelope['code'],
            'message' => $envelope['message'],
            'payload' => $envelope['payload'],
            'body' => $raw,
            'request' => ['method' => $method, 'body' => $bizContent],
        ];
    }

    /**
     * 把网关回执折算成统一的 `WmsResult`（供 Adapter 复用，避免每处重复判定）。
     *
     * 判定顺序：
     * 1. `flag=success` → 成功；
     * 2. code 命中「单据已存在」→ **幂等成功**（`success=true, idempotent=true`），
     *    这是最容易被忽略却最要命的一类：重试时对方说"已存在"，业务上等于成功；
     * 3. 其余 → 失败，可重试性由 {@see CainiaoErrorCode::retryable()} 判定。
     *
     * @param  array<string, mixed>  $envelope  {@see self::post()} 的返回
     * @param  list<string>  $duplicateCodes
     */
    public function toResult(array $envelope, array $duplicateCodes = CainiaoErrorCode::DEFAULT_DUPLICATE_CODES): WmsResult
    {
        $status = (int) ($envelope['http_status'] ?? 200);
        $flag = $envelope['flag'] ?? null;
        $code = $envelope['code'] ?? null;
        $payload = (array) ($envelope['payload'] ?? []);
        $duration = (int) ($envelope['duration_ms'] ?? 0);
        $raw = [
            'http_status' => $status,
            'flag' => $flag,
            'code' => $code,
            'message' => $envelope['message'] ?? null,
            'payload' => $payload,
            'request_id' => $envelope['request_id'] ?? null,
        ];

        if ($flag === CainiaoErrorCode::FLAG_SUCCESS) {
            return WmsResult::ok($payload + ['request_id' => $envelope['request_id'] ?? null], $status, $raw, $duration);
        }

        $message = CainiaoErrorCode::describe($code, $envelope['message'] ?? null);

        if (CainiaoErrorCode::isDuplicate($code, $duplicateCodes)) {
            // 幂等成功：业务意图已达成（单据在对方系统里），绝不能再建第二张
            return WmsResult::ok(
                $payload + ['request_id' => $envelope['request_id'] ?? null, 'idempotent' => true],
                $status,
                $raw,
                $duration,
                idempotent: true,
            );
        }

        return WmsResult::fail(
            $message,
            $status,
            $raw,
            $duration,
            retryable: CainiaoErrorCode::retryable($status, $code),
        );
    }

    // ---------------- 内部 ----------------

    /**
     * 系统参数（奇门公共参数）。`sign` 由调用方补在最后。
     *
     * @return array<string, string>
     */
    private function systemParams(string $method, WmsConfig $config): array
    {
        return [
            'method' => $method,
            'app_key' => (string) $config->app_key,
            'customerId' => (string) ($config->customer_id ?? ''),
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'format' => 'json',
            'v' => (string) config('wms.providers.cainiao.version', '2.0'),
            'sign_method' => $this->signMethod(),
        ];
    }

    /**
     * 从回执里挖出 `flag / code / message` 与业务载荷。
     *
     * 奇门回执有三套常见外形，都得认：
     * - `{"response":{...,"flag":"success"}}`（标准奇门）
     * - `{"deliveryorder_create_response":{...,"flag":"success"}}`（TOP 映射式）
     * - `{"error_response":{"code":25,"msg":"Invalid signature"}}`（TOP 错误式）
     *
     * 找不到信封时**不报错**：把整包当 payload 返回、flag 置 null，
     * 由上层按「非 success」处理——比抛异常更利于排查（至少能看到原文）。
     *
     * @return array{flag: string|null, code: string|null, message: string|null, payload: array<string, mixed>}
     */
    private function extractEnvelope(array $json): array
    {
        // TOP 错误式：code 是数字（如 25 表示签名错），字段名是 msg/sub_msg
        if (isset($json['error_response']) && is_array($json['error_response'])) {
            $error = $json['error_response'];

            return [
                'flag' => CainiaoErrorCode::FLAG_FAILURE,
                'code' => $this->stringOrNull($error['sub_code'] ?? $error['code'] ?? null),
                'message' => $this->stringOrNull($error['sub_msg'] ?? $error['msg'] ?? null),
                'payload' => $json,
            ];
        }

        // 标准式：根层就有 flag
        if (isset($json['flag'])) {
            return $this->envelopeFrom($json);
        }

        // 包裹式：根层只有一个业务键，其值里带 flag（业务数据在该层内，如 response / xxx_response）
        foreach ($json as $key => $value) {
            if (is_string($key) && is_array($value) && isset($value['flag'])) {
                return $this->envelopeFrom($value);
            }
        }

        return [
            'flag' => null,
            'code' => null,
            'message' => null,
            'payload' => $json,
        ];
    }

    /**
     * @param  array<string, mixed>  $node  含 flag 的那一层（业务数据即在该层）
     * @return array{flag: string|null, code: string|null, message: string|null, payload: array<string, mixed>}
     */
    private function envelopeFrom(array $node): array
    {
        // 业务载荷 = 含 flag 的那一层去掉纯信封字段（保留嵌套结构，如 items.item / orderLines.orderLine）
        $payload = $node;
        foreach (['flag', 'code', 'message', 'msg', 'success'] as $envelopeKey) {
            if (isset($payload[$envelopeKey]) && is_scalar($payload[$envelopeKey])) {
                unset($payload[$envelopeKey]);
            }
        }

        return [
            'flag' => $this->stringOrNull($node['flag'] ?? null),
            'code' => $this->stringOrNull($node['code'] ?? null),
            'message' => $this->stringOrNull($node['message'] ?? $node['msg'] ?? null),
            'payload' => $payload,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /** 网关地址（按环境取）；未配置即 fail-closed */
    private function gatewayUrl(WmsConfig $config): string
    {
        $env = (string) $config->api_env;
        $url = trim((string) (config("wms.providers.cainiao.gateway.{$env}") ?? ''));

        if ($url === '') {
            throw BusinessException::badRequest(
                "未配置菜鸟网关地址（环境：{$env}），已拒绝调用。".
                "请在 .env 设置 WMS_CAINIAO_GATEWAY_".strtoupper($env)
            );
        }

        return $url;
    }

    /** 凭证齐备性（fail-closed）；返回 AppSecret 明文供签名使用 */
    private function assertCredentials(WmsConfig $config): string
    {
        $appKey = trim((string) $config->app_key);
        $secret = (string) $config->app_secret;

        if ($appKey === '' || $secret === '') {
            throw BusinessException::badRequest('菜鸟凭证不完整（AppKey/AppSecret），已拒绝调用');
        }

        return $secret;
    }

    private function signMethod(): string
    {
        return (string) config('wms.providers.cainiao.sign_method', Signature::METHOD_MD5);
    }

    private function signWrap(): string
    {
        return (string) config('wms.providers.cainiao.sign_secret_wrap', Signature::WRAP_BOTH);
    }

    private function timeout(): int
    {
        return max(1, (int) config('wms.providers.cainiao.timeout', 15));
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('wms.providers.cainiao.connect_timeout', 5));
    }
}
