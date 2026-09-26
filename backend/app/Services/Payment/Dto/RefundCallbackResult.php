<?php

namespace App\Services\Payment\Dto;

/**
 * 微信退款回调验签结果（verifyRefundCallback 产出）
 *
 * 与支付回调 CallbackResult 不同：退款回调关注的是 out_refund_no + refund_status，
 * 供 RefundService 反查 Refund 并落库终态。
 */
class RefundCallbackResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $outRefundNo = '',
        public readonly string $channelStatus = '',
        public readonly string $eventType = '',
        public readonly array $raw = [],
    ) {
    }

    public static function fail(string $message = '', array $raw = []): self
    {
        return new self(false, '', '', '', $raw);
    }

    public static function success(
        string $outRefundNo,
        string $channelStatus,
        string $eventType = '',
        array $raw = [],
    ): self {
        return new self(true, $outRefundNo, $channelStatus, $eventType, $raw);
    }
}
