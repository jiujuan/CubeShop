<?php

namespace App\Console\Commands;

use App\Models\WmsApiLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * WMS 报文日志清理（WMS 计划 P5 / F7）
 *
 * `wms_api_logs` 保留 ≥90 天供排障与重放，超期的整行删除。
 * ⚠️ 只删整行，**不做任何字段级改写**——脱敏规则由 `PayloadMasker` 在写入时
 * 保证，清理环节不存在绕过脱敏的路径。
 */
class WmsPruneLogs extends Command
{
    protected $signature = 'wms:prune-logs
        {--days= : 保留天数（默认 config wms.logs.prune_days，缺省 90）}';

    protected $description = '清理过期的 WMS 接口报文日志（wms_api_logs）';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('wms.logs.prune_days', 90));
        // 下限保护：误传 0 或负数会把排障依据清空
        $days = max(1, $days);
        $before = Carbon::now()->subDays($days);

        $deleted = WmsApiLog::query()->where('created_at', '<', $before)->delete();

        $this->info("已清理 {$days} 天前的 WMS 报文日志 {$deleted} 条（< {$before->toDateTimeString()}）");

        return self::SUCCESS;
    }
}
