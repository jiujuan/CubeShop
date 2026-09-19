<?php

namespace App\Services\Wms\Callback;

use App\Exceptions\Wms\WmsGatewayException;
use App\Jobs\Wms\ProcessWmsCallbackJob;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Services\Wms\Adapters\Cainiao\Signature;
use App\Services\Wms\Support\PayloadMasker;
use Illuminate\Support\Facades\Cache;

/**
 * WMS 回调同步编排（WMS 计划 P3 / Step 2，快进快出）
 *
 * 同步阶段只做**安全校验 + 落痕 + 入队**，业务处理全部异步
 * （{@see ProcessWmsCallbackJob}）——回调方超时重推是风暴之源。
 *
 * 返回值即响应给菜鸟的 JSON（对方只认 `flag`）：
 * ```
 * ['flag' => 'success', 'code' => '0',        'message' => 'ok']
 * ['flag' => 'failure', 'code' => 'XXX',      'message' => '...']
 * ```
 *
 * 失败语义（返回 failure 让对方重推）：
 * - 报文非 JSON / 缺 sign / 验签不过 / IP 拒绝 → 安全校验层失败；
 * - 防重放命中 → 返回 **success**（同一条已收过，success 让对方停止重推）。
 */
class WmsCallbackService
{
    public function __construct(
        private readonly Signature $signature,
        private readonly PayloadMasker $masker,
        private readonly CallbackDeduplicator $deduplicator,
    ) {}

    /**
     * @param  array<string, mixed>  $query  URL 查询参数（系统参数可能在此）
     */
    public function receive(string $provider, string $rawBody, array $query, string $ip): array
    {
        $logId = null;

        try {
            $payload = json_decode($rawBody, true);
            if (! is_array($payload)) {
                return $this->fail($provider, $rawBody, null, $ip, 'INVALID_JSON', '报文不是合法 JSON');
            }

            // 1) 定位配置：优先 URL 的 ?token=（P0 callbackUrl 约定，unique），fallback 报文 app_key
            //   （奇门网关转发推送时未必保留 URL 参数，故保留 app_key 通道）
            $token = (string) ($query['token'] ?? '');
            $appKey = (string) ($payload['app_key'] ?? $query['app_key'] ?? '');

            $config = $token !== ''
                ? WmsConfig::query()->where('callback_token', $token)->first()
                : null;
            $config ??= $appKey !== ''
                ? WmsConfig::query()->where('provider', $provider)->where('app_key', $appKey)->first()
                : null;

            if (! $config) {
                return $this->fail($provider, $rawBody, null, $ip, 'APP_KEY_NOT_FOUND', "回调无法定位 WMS 配置（token/app_key 均未命中）");
            }

            // 2) 系统参数 = query ∪ body 顶层标量字段（去掉业务载荷），验签原文用原始 body
            $systemParams = $query;
            foreach ($payload as $key => $value) {
                if (is_scalar($value)) {
                    $systemParams[$key] = $value;
                }
            }

            $sign = (string) ($systemParams['sign'] ?? '');
            $signMethod = (string) ($systemParams['sign_method'] ?? 'md5');
            if ($sign === '') {
                return $this->fail($provider, $rawBody, $config, $ip, 'SIGN_MISSING', '回调缺少签名');
            }

            if (! $this->signature->verify($systemParams, $this->secretOf($config), $sign, $rawBody, $signMethod)) {
                return $this->fail($provider, $rawBody, $config, $ip, 'SIGNATURE_INVALID', '回调验签失败');
            }

            // 3) IP 白名单（空=不限制；生产建议配置）
            $whitelist = array_values(array_filter((array) config('wms.callback.ip_whitelist', [])));
            if ($whitelist !== [] && ! in_array($ip, $whitelist, true)) {
                return $this->fail($provider, $rawBody, $config, $ip, 'IP_FORBIDDEN', "回调来源 IP {$ip} 不在白名单");
            }

            // 4) 防重放（同一条原始报文短窗口重复）：success 让对方停止重推
            if (! $this->deduplicator->claimRaw($provider, $rawBody)) {
                $this->log($config, 'callback_replay', true, $provider, $rawBody, $payload, 'replay_dropped');

                return $this->ok('已处理过（重放丢弃）');
            }

            // 5) 落 inbound 日志 → 解析 → 入队
            $logId = $this->log($config, 'callback_receive', true, $provider, $rawBody, $payload);

            $message = app(CallbackMessageParser::class)->parse($payload);

            ProcessWmsCallbackJob::dispatch($provider, $message, $logId);

            return $this->ok();
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($provider, $rawBody, null, $ip, 'INTERNAL_ERROR', '回调处理内部错误');
        }
    }

    /** 解密取 secret（封装一层便于测试打桩） */
    private function secretOf(WmsConfig $config): string
    {
        return (string) $config->app_secret;
    }

    /**
     * 落一条 wms_api_logs（direction=inbound），返回日志 id。
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function log(
        WmsConfig $config,
        string $apiName,
        bool $success,
        string $provider,
        string $rawBody,
        ?array $payload,
        ?string $remark = null,
        string $errorMsg = '',
    ): int {
        try {
            $log = WmsApiLog::create([
                'direction' => WmsApiLog::DIRECTION_INBOUND,
                'provider' => $provider,
                'api_name' => $apiName,
                'request_id' => (string) ($payload['request_id'] ?? null) ?: null,
                'biz_no' => $payload['deliveryOrderCode']
                    ?? $payload['deliveryOrder']['deliveryOrderCode']
                    ?? null,
                'request_body' => [
                    'masked_raw' => $this->masker->mask($payload ?? []),
                    'raw_length' => strlen($rawBody),
                ],
                'response_body' => $remark !== null ? ['remark' => $remark] : [],
                'http_status' => 200,
                'success' => $success,
                'error_msg' => $errorMsg,
                'created_at' => now(),
            ]);

            return (int) $log->id;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function ok(string $message = 'ok'): array
    {
        return ['flag' => 'success', 'code' => '0', 'message' => $message];
    }

    private function fail(
        string $provider,
        string $rawBody,
        ?WmsConfig $config,
        string $ip,
        string $code,
        string $message,
    ): array {
        try {
            if ($config) {
                $this->log($config, 'callback_reject', false, $provider, $rawBody, null, null, "{$code}: {$message}");
            } else {
                // 连配置都定位不到：只留一条纯文字痕迹（无 config 也能落，provider 直接填）
                WmsApiLog::create([
                    'direction' => WmsApiLog::DIRECTION_INBOUND,
                    'provider' => $provider,
                    'api_name' => 'callback_reject',
                    'request_id' => null,
                    'biz_no' => null,
                    'request_body' => ['raw_length' => strlen($rawBody), 'ip' => $ip],
                    'response_body' => [],
                    'http_status' => 200,
                    'success' => false,
                    'error_msg' => "{$code}: {$message}",
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['flag' => 'failure', 'code' => $code, 'message' => $message];
    }
}
