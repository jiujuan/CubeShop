<?php

namespace App\Services\Payment\Dto;

/**
 * 退款结果
 */
class RefundResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly ?string $refundNo = null,
        public readonly array $raw = [],
        public readonly ?string $channelStatus = null,
    ) {}

    public static function fail(string $message, array $raw = [], ?string $channelStatus = null): self
    {
        return new self(false, $message, raw: $raw, channelStatus: $channelStatus);
    }

    public static function success(?string $refundNo = null, array $raw = [], ?string $channelStatus = null): self
    {
        return new self(true, 'ok', $refundNo, $raw, $channelStatus);
    }
}
