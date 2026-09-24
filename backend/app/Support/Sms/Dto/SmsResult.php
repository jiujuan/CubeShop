<?php

namespace App\Support\Sms\Dto;

/**
 * 短信发送结果（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D2
 *
 * 存在的理由与 `Support\Shipping\TraceResult` 同款：把「成功/失败 + 原因 + 原始响应」
 * 收成一个值对象，让适配器不用抛异常也能表达失败，调用方不必写 try/catch 就能落日志。
 *
 * ⚠️ `raw` 只放**服务商响应体**（可含手机号等已发送信息），不放模板参数——
 *    验证码场景的模板参数就是验证码明文。
 */
final class SmsResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMsg,
        public readonly array $raw,
        public readonly ?int $latencyMs,
    ) {
    }

    public static function ok(
        ?string $providerMessageId = null,
        array $raw = [],
        ?int $latencyMs = null,
    ): self {
        return new self(true, $providerMessageId, null, null, $raw, $latencyMs);
    }

    public static function fail(
        string $errorCode,
        ?string $errorMsg = null,
        array $raw = [],
        ?int $latencyMs = null,
    ): self {
        return new self(false, null, $errorCode, $errorMsg, $raw, $latencyMs);
    }

    /** 落库用扁平结构（与 `sms_logs` 列一一对应） */
    public function toArray(): array
    {
        return [
            'status' => $this->ok ? 'sent' : 'failed',
            'biz_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
            'error_msg' => $this->errorMsg !== null ? mb_substr($this->errorMsg, 0, 255) : null,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
