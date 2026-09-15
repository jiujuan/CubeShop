<?php

namespace App\Services\Payment\Dto;

/**
 * 回调验签后的标准化结果
 */
class CallbackResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly string $paymentNo = '',
        public readonly string $channelTradeNo = '',
        public readonly string $amount = '',
        public readonly string $status = '',
        public readonly array $raw = [],
    ) {}

    public static function fail(string $message, array $raw = []): self
    {
        return new self(false, $message, raw: $raw);
    }

    public static function success(
        string $paymentNo,
        string $channelTradeNo,
        string $amount,
        string $status,
        array $raw = [],
    ): self {
        return new self(true, 'ok', $paymentNo, $channelTradeNo, $amount, $status, $raw);
    }
}
