<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentReconcileService;
use Illuminate\Console\Command;

/**
 * 支付渠道日终对账（A7-支付渠道对账）
 *
 * 比对「渠道日账单 / 回调日志」与「本地 success 支付单」，差异固化成工单交运营处置。
 * 对账只发现差异、不改支付单；处置走后台 resolve/ignore。
 *
 * 每日 02:00 由调度触发（见 routes/console.php）；也可手动补跑指定日期。
 */
class PaymentReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile
        {--date= : 对账日期 YYYY-MM-DD，默认昨天}
        {--channel= : 仅对账指定渠道（wechat/alipay/mock），可多次传入}';

    protected $description = '支付渠道日终对账：渠道流水 ↔ 本地支付单，差异生成工单';

    public function handle(PaymentReconcileService $service): int
    {
        $date = $this->option('date');
        if ($date && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('日期格式应为 YYYY-MM-DD');

            return self::FAILURE;
        }

        $channels = $this->option('channel');
        // 未传 --channel（每日调度即如此）时用空数组兜底成 null，让引擎自动决定渠道集合；
        // 否则 (array) 包成列表。Laravel 数组选项未传时返回 []，不能直接喂给 run()。
        $channelList = ! empty($channels) ? (array) $channels : null;

        $this->info('开始支付渠道对账'.($date ? "：{$date}" : '（昨天）'));

        $summary = $service->run($date, $channelList);

        foreach ($summary['runs'] as $run) {
            $this->line(sprintf(
                '  [%s] %s：本地 %d / 渠道 %d / 匹配 %d / 差异 %d',
                $run['status'],
                $run['channel'],
                $run['local_count'],
                $run['channel_count'],
                $run['matched_count'],
                $run['diff_count'],
            ));
        }

        $this->info(sprintf('对账完成：%d 个渠道，%d 条待处理差异', $summary['total_runs'], $summary['total_diff']));

        return self::SUCCESS;
    }
}
