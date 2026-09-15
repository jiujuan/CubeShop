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
    ) {}

    public static function fail(string $message, array $raw = []): self
    {
        return new self(false, $message, raw: $raw);
    }

    public static function success(?string $refundNo = null, array $raw = []): self
    {
        return new self(true, 'ok', $refundNo, $raw);
    }
}
