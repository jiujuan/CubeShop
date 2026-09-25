<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Payment\Dto\ChannelTransaction;

/**
 * 日志源提供方：以「本系统收到的成功回调」作为渠道侧视角（A7-支付渠道对账）
 *
 * 适用于：
 * - 余额/线下等无远程账单的渠道（引擎自动改走这里）；
 * - 在线渠道账单拉取失败时的降级源。
 *
 * 能稳定捕获：重复回调（同一交易号多条成功回调）、金额不一致（回调金额 vs 本地）、
 * 本地 success 但无对应成功回调（资金风险）。
 * 无法捕获「渠道已到账但回调彻底丢失」的真漏单——那种需要真实渠道账单（BillReconciliationProvider）。
 */
class LogReconciliationProvider
{
    public function provide(string $channel, string $date): array
    {
        $logs = PaymentLog::query()
            ->where('event', PaymentLog::EVENT_CALLBACK)
            ->whereDate('created_at', $date)
            ->orderBy('id')
            ->get();

        $txns = [];

        foreach ($logs as $log) {
            $req = $log->request_data ?? [];
            $channelTradeNo = (string) ($req['channel_trade_no'] ?? ($req['trade_no'] ?? ''));
            $paymentNo = (string) ($req['payment_no'] ?? '');
            $status = (string) ($req['status'] ?? '');

            if ($channelTradeNo === '' && $paymentNo === '') {
                continue;
            }

            $payment = Payment::query()->where('payment_no', $paymentNo)->first();
            if (! $payment || $payment->channel !== $channel) {
                continue;
            }
            if ($status !== '' && $status !== Payment::STATUS_SUCCESS) {
                continue;
            }

            $amount = (string) ($req['amount'] ?? $payment->amount);
            $txns[] = new ChannelTransaction(
                $channelTradeNo !== '' ? $channelTradeNo : $paymentNo,
                $paymentNo !== '' ? $paymentNo : null,
                $amount,
                'paid',
                $log->created_at,
            );
        }

        return $txns;
    }
}
