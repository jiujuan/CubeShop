<?php

namespace App\Services\Payment\Dto;

/**
 * 渠道日账单拉取结果（A7-支付渠道对账）
 *
 * - ok         ：本次拉取是否成功（网络/解析无误）
 * - supported  ：该渠道是否支持远程账单（余额/线下为 false，引擎改走日志源）
 * - transactions：渠道交易明细（ChannelTransaction[]）
 */
class StatementResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $supported,
        public readonly array $transactions,
        public readonly string $message = '',
    ) {}

    /** 渠道无远程账单（余额/线下），引擎应改走日志源 */
    public static function unsupported(string $message = '该渠道无远程账单'): self
    {
        return new self(false, false, [], $message);
    }

    /** 拉取/解析失败（网络/鉴权/格式），引擎应降级到日志源 */
    public static function fail(string $message, array $transactions = []): self
    {
        return new self(false, true, $transactions, $message);
    }

    /** 拉取成功 */
    public static function success(array $transactions, string $message = 'ok'): self
    {
        return new self(true, true, $transactions, $message);
    }
}
