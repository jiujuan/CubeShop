<?php

namespace App\Services\Wms\Dto;

/**
 * WMS 调用结果（Adapter 统一出口）
 *
 * 所有 Adapter 方法一律返回本对象，不抛「网络层」异常——失败以 success=false + error 表达，
 * 由上层（WmsConfigService / Job）决定重试或落库。**参数/配置错误**（如缺凭证）才抛
 * `BusinessException`，因为那类错误重试无意义。
 */
final class WmsResult
{
    /**
     * @param  array<string, mixed>  $data  业务数据（成功时的报文体）
     * @param  array<string, mixed>  $raw   原始报文（已脱敏，落 wms_api_logs）
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?int $httpStatus = null,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly array $raw = [],
        public readonly int $durationMs = 0,
    ) {}

    public static function ok(array $data = [], ?int $httpStatus = 200, array $raw = [], int $durationMs = 0): self
    {
        return new self(true, $httpStatus, $data, null, $raw ?: $data, $durationMs);
    }

    public static function fail(string $error, ?int $httpStatus = null, array $raw = [], int $durationMs = 0): self
    {
        return new self(false, $httpStatus, [], $error, $raw, $durationMs);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'http_status' => $this->httpStatus,
            'data' => $this->data,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }
}
