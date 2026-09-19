<?php

namespace App\Console\Commands;

use App\Models\WmsCallbackDedup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 回调幂等登记清理（WMS 计划 P3 / Step 6）
 *
 * `wms_callback_dedups` 只为幂等兜底，超过保留窗口的历史登记无查询价值
 * （重复推送的窗口远小于 90 天）。注册每日调度。
 */
class WmsPruneCallbacks extends Command
{
    protected $signature = 'wms:prune-callbacks
        {--days= : 保留天数（默认 config wms.callback.prune_days，缺省 90）}';

    protected $description = '清理过期的 WMS 回调幂等登记（wms_callback_dedups）';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('wms.callback.prune_days', 90));
        $before = Carbon::now()->subDays($days);

        $deleted = WmsCallbackDedup::query()->where('received_at', '<', $before)->delete();

        $this->info("已清理 {$days} 天前的回调幂等登记 {$deleted} 条（< {$before->toDateString()}）");

        return self::SUCCESS;
    }
}
