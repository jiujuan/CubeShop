<?php

namespace App\Services\Wms\Dto;

/**
 * WMS 调用结果（Adapter 统一出口）
 *
 * 所有 Adapter 方法一律返回本对象，不抛「网络层」异常——失败以 success=false + error 表达，
 * 由上层（WmsConfigService / Job）决定重试或落库。**参数/配置错误**（如缺凭证）才抛
 * `BusinessException`，因为那类错误重试无意义。
 *
 * P2 新增两个**处置语义**字段（带默认值，P0/P1 既有调用点零改动）：
 * - `retryable`：失败是否值得重试。网络抖动 / 对方 5xx → true；业务校验失败 → false。
 *   `PushOutboundJob` 据此决定「退避重试」还是「直接转人工」，避免无谓打扰对方；
 * - `idempotent`：本次调用其实是**幂等命中**（对方回「单据已存在」），业务上等于成功。
 *   调用方据此记下 WMS 单号即可，**绝不能重复建单**。
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
        public readonly bool $retryable = true,
        public readonly bool $idempotent = false,
    ) {}

    public static function ok(
        array $data = [],
        ?int $httpStatus = 200,
        array $raw = [],
        int $durationMs = 0,
        bool $idempotent = false,
    ): self {
        return new self(true, $httpStatus, $data, null, $raw ?: $data, $durationMs, false, $idempotent);
    }

    /**
     * 失败结果。
     *
     * `$retryable` 默认 true：绝大多数失败（网络抖动、对方系统异常、未预期报文）先重试更稳；
     * 仅当明确判定为「业务校验失败」或「我方配置/数据有误」时才传 false。
     */
    public static function fail(
        string $error,
        ?int $httpStatus = null,
        array $raw = [],
        int $durationMs = 0,
        bool $retryable = true,
    ): self {
        return new self(false, $httpStatus, [], $error, $raw, $durationMs, $retryable, false);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'http_status' => $this->httpStatus,
            'data' => $this->data,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'retryable' => $this->retryable,
            'idempotent' => $this->idempotent,
        ];
    }

    /**
     * 换掉 `data`（保留 success / error / 耗时 / 处置语义）的副本。
     *
     * Adapter 拿到回执后需要把协议字段翻译成平台字段（如奇门 `deliveryOrderId`
     * → 平台 `wms_order_no`），本方法让这次翻译不必重新拼装整个结果对象。
     *
     * @param  array<string, mixed>  $data
     */
    public function with(array $data): self
    {
        return new self(
            $this->success,
            $this->httpStatus,
            $data,
            $this->error,
            $this->raw,
            $this->durationMs,
            $this->retryable,
            $this->idempotent,
        );
    }
}
