<?php

namespace App\Services\Payment\Dto;

/**
 * 后台「测试连接」结果
 */
class TestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $detail = [],
    ) {}

    public static function ok(string $message, array $detail = []): self
    {
        return new self(true, $message, $detail);
    }

    public static function fail(string $message, array $detail = []): self
    {
        return new self(false, $message, $detail);
    }

    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, 'detail' => $this->detail];
    }
}
