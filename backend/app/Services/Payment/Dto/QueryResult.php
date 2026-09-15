<?php

namespace App\Services\Payment\Dto;

/**
 * 主动查单结果
 */
class QueryResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly ?string $status = null,
        public readonly ?string $channelTradeNo = null,
        public readonly ?string $amount = null,
        public readonly array $raw = [],
    ) {}

    public static function fail(string $message, array $raw = []): self
    {
        return new self(false, $message, raw: $raw);
    }

    public static function success(?string $status, ?string $channelTradeNo = null, ?string $amount = null, array $raw = []): self
    {
        return new self(true, 'ok', $status, $channelTradeNo, $amount, $raw);
    }
}
