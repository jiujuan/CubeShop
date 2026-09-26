<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payment\PaymentChannelService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Refund\RefundService;
use Illuminate\Console\Command;

/**
 * 退款异步兜底轮询（Phase 4）
 *
 * 调度：每 15 分钟（routes/console.php，withoutOverlapping）
 * 规则：扫描 status=processing 且渠道为微信的退款单（已等待至少 2 分钟让回调优先）→
 *       调网关 queryRefund → 落库（SUCCESS / ABNORMAL / CLOSED / PROCESSING）；
 *       仍为 processing 且超过 30 分钟阈值 → 标记 failed 转人工并告警。
 */
class RefundSyncCommand extends Command
{
    protected $signature = 'refunds:sync-processing {--limit=200 : 单次最多处理的退款单数}';

    protected $description = '退款异步兜底：扫描超时未确认的微信退款 processing 单并向渠道查单';

    /** 至少等待该分钟数，优先等待渠道主动回调 */
    private const GRACE_MINUTES = 2;

    /** processing 超过该分钟数仍未确认 → 标记 failed 转人工 */
    private const TIMEOUT_MINUTES = 30;

    public function handle(RefundService $refundService, PaymentGatewayFactory $factory, PaymentChannelService $channels): int
    {
        $now = now();

        $processing = Refund::query()
            ->where('status', Refund::STATUS_PROCESSING)
            ->where('channel', Payment::CHANNEL_WECHAT)
            ->where('created_at', '<=', $now->copy()->subMinutes(self::GRACE_MINUTES))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $scanned = 0;
        $succeeded = 0;
        $failed = 0;
        $stillProcessing = 0;

        foreach ($processing as $refund) {
            $scanned++;

            $payment = Payment::where('order_id', $refund->order_id)
                ->where('biz_type', Payment::BIZ_TYPE_ORDER)
                ->where('status', Payment::STATUS_SUCCESS)
                ->first();

            // 找不到成功支付单：无法查单，直接转失败交人工
            if (! $payment) {
                $refundService->failRefund($refund, '轮询兜底：找不到对应的成功支付单', 'ABNORMAL');
                $failed++;
                $this->warn("退款 {$refund->out_refund_no} 找不到支付单，转 failed");

                continue;
            }

            $gateway = $factory->make($refund->channel);
            if (! method_exists($gateway, 'queryRefund')) {
                // 非在线渠道（余额 / Mock）无异步查单，保持 processing（理论上已按 wechat 过滤不会进入）
                $stillProcessing++;
                continue;
            }

            $config = $channels->decryptedConfig($refund->channel);
            $result = $gateway->queryRefund($payment, (string) $refund->out_refund_no, $config);
            $refundService->applyQueryResult($refund, $result);

            $current = $refund->fresh()->status;
            if ($current === Refund::STATUS_SUCCESS) {
                $succeeded++;
                $this->line("退款 {$refund->out_refund_no} 查单确认成功");
            } elseif ($current === Refund::STATUS_FAILED) {
                $failed++;
                $this->warn("退款 {$refund->out_refund_no} 查单失败，转人工");
            } elseif ($refund->created_at->lte($now->copy()->subMinutes(self::TIMEOUT_MINUTES))) {
                $refundService->failRefund($refund, '退款处理超过 '.self::TIMEOUT_MINUTES.' 分钟未确认，转人工', 'TIMEOUT');
                $failed++;
                $this->warn("退款 {$refund->out_refund_no} 超时，转 failed");
            } else {
                $stillProcessing++;
            }
        }

        $this->info("退款轮询兜底完成：扫描 {$scanned} 笔，成功 {$succeeded} 笔，转人工 {$failed} 笔，仍处理中 {$stillProcessing} 笔");

        return self::SUCCESS;
    }
}
