<?php

namespace App\Console\Commands;

use App\Services\Refund\RefundReconcileService;
use Illuminate\Console\Command;

/**
 * 退款流水对账命令（Phase 5）
 *
 * 比对本地退款单与渠道侧退款状态，差异写入 payment_reconciliation_diffs。
 * 默认对账昨天、渠道 wechat/alipay；支持 --date / --channel 限定。
 */
class RefundReconcileCommand extends Command
{
    protected $signature = 'refunds:reconcile
        {--date= : 对账日 YYYY-MM-DD，默认昨天}
        {--channel= : 限定渠道，逗号分隔（如 wechat,alipay），默认 wechat,alipay}';

    protected $description = '退款流水对账：比对本地退款单与渠道侧退款状态，差异写入对账差异表';

    public function handle(RefundReconcileService $service): int
    {
        $date = $this->option('date') ?: null;
        $channelOpt = $this->option('channel');
        $channels = $channelOpt ? explode(',', $channelOpt) : null;

        $result = $service->run($date, $channels);

        $this->info(sprintf(
            '退款对账完成：date=%s channels=%s diff_count=%d',
            $result['date'],
            implode(',', $result['channels']),
            $result['diff_count'],
        ));

        return self::SUCCESS;
    }
}
