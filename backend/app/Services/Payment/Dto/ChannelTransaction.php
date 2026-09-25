<?php

namespace App\Services\Payment\Dto;

use Carbon\Carbon;

/**
 * 渠道侧一笔交易（来自渠道日账单 / 回调日志）
 *
 * - tradeNo       ：渠道交易号（channel_trade_no），对账主匹配键
 * - outTradeNo    ：平台支付单号（payment_no），辅助匹配键
 * - amount        ：交易金额（字符串，单位元，与 payments.amount 同口径，不含手续费）
 * - status        ：渠道侧状态，约定 'paid' / 'unpaid' / 其它原样透传
 * - paidAt        ：渠道侧支付成功时间
 */
class ChannelTransaction
{
    public function __construct(
        public readonly string $tradeNo,
        public readonly ?string $outTradeNo = null,
        public readonly ?string $amount = null,
        public readonly ?string $status = null,
        public readonly ?Carbon $paidAt = null,
        public readonly array $raw = [],
    ) {}
}
