<?php

namespace App\Services\Payment\Dto;

/**
 * 退款查单结果（微信异步退款必需；支付宝/余额同步可返回 unsupported）
 */
class RefundQueryResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $channelStatus = '',
        public readonly ?string $refundNo = null,
        public readonly array $raw = [],
    ) {}

    public static function fail(string $channelStatus = 'unsupported', array $raw = []): self
    {
        return new self(false, $channelStatus, null, $raw);
    }

    public static function success(string $channelStatus, ?string $refundNo = null, array $raw = []): self
    {
        return new self(true, $channelStatus, $refundNo, $raw);
    }
}
