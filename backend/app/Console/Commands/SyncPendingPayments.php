<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * 主动查单补偿调度（收银台方案 §7.2）
 *
 * 调度：每分钟执行（routes/console.php，withoutOverlapping）
 * 规则：扫描 status=pending 且创建于 2~30 分钟前的在线支付单（wechat/alipay/mock），
 *       调网关 query()；同一支付单主动查单次数达到 payment.query_max_attempts 后标记 failed 并记日志。
 */
class SyncPendingPayments extends Command
{
    protected $signature = 'payments:sync-pending {--limit=200 : 单次最多处理的支付单数}';

    protected $description = '主动查单补偿：扫描卡在处理中的在线支付单并向渠道查单';

    public function handle(PaymentService $payments): int
    {
        $now = now();

        $pending = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereIn('channel', [Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY, Payment::CHANNEL_MOCK])
            ->where('created_at', '<=', $now->copy()->subMinutes(2))
            ->where('created_at', '>=', $now->copy()->subMinutes(30))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $maxAttempts = $payments->queryMaxAttempts();
        $scanned = 0;
        $synced = 0;
        $exhausted = 0;

        foreach ($pending as $payment) {
            $scanned++;

            // 超出查单次数：标记失败并记日志，避免无限轮询
            if ($payments->queryAttempts($payment) >= $maxAttempts) {
                $payments->markQueryExhausted($payment);
                $exhausted++;
                $this->warn("查单超限标记失败：{$payment->payment_no}");

                continue;
            }

            $result = $payments->sync($payment);
            if (($result['status'] ?? null) === Payment::STATUS_SUCCESS) {
                $synced++;
                $this->line("已补单成功：{$payment->payment_no}");
            }
        }

        $this->info("查单补偿完成：扫描 {$scanned} 笔，补单成功 {$synced} 笔，超限失败 {$exhausted} 笔");

        return self::SUCCESS;
    }
}
