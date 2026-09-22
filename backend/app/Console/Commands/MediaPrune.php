<?php

namespace App\Console\Commands;

use App\Services\Common\MediaRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 图片回收（媒体治理 P0）
 *
 * 三段式策略里的**最后一段**，也是最危险的，因此默认什么也不删：
 *
 * | 命令 | 行为 |
 * |---|---|
 * | `php artisan media:prune --notify` | 列出满窗待回收清单并写日志（**默认日常调度即是这个**） |
 * | `php artisan media:prune --dry-run` | 预演：逐条列出将被物理删除的文件，不落任何改动 |
 * | `php artisan media:prune --force` | 真删：删除磁盘文件并彻底移除登记记录，不可恢复 |
 *
 * 前置：`MediaRegistry::releaseReferences()` / `reclaimUnused()` 已把引用归零的记录
 * **软删**（`deleted_at` 起算 30 天窗口）；本命令只处理「已满 30 天且仍无引用」的那部分。
 */
class MediaPrune extends Command
{
    protected $signature = 'media:prune
        {--days= : 回收窗口天数，默认 30}
        {--dry-run : 只预演，列出将被删除的文件}
        {--notify : 输出到期清单并记录通知日志（不删除）}
        {--force : 真正执行物理删除（必须显式声明）}
        {--yes : 跳过交互确认，供自动化 / 定时任务使用}';

    protected $description = '回收软删满窗口且无引用的图片（默认只通知不删除）';

    public function handle(MediaRegistry $registry): int
    {
        // ⚠️ 不能用 `?:` —— 那是同时 test **0** 也会被当成「没传」而回落到 30
        $days = $this->option('days') !== null ? (int) $this->option('days') : MediaRegistry::RECLAIM_DAYS;
        $due = $registry->dueForReclaim($days);

        if ($due->isEmpty()) {
            $this->info("没有满 {$days} 天的待回收图片。");

            return self::SUCCESS;
        }

        // 优先级：dry-run > notify（默认）> force。三者互斥语义明确，不玩组合拳。
        if ($this->option('dry-run')) {
            $this->warn("预演：以下 {$due->count()} 张将被物理删除：");
            foreach ($due as $media) {
                $this->line('  '.$media->path.'（'.$this->humanSize((int) $media->size).'）');
            }
            $this->info('预演结束，未做任何改动。');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            return $this->notifyOnly($due, $days);
        }

        if (! $this->option('yes') && ! $this->confirm("确认物理删除 {$due->count()} 个文件？（不可恢复）", false)) {
            $this->info('已取消。');

            return self::SUCCESS;
        }

        $deleted = $registry->prune($days, false, function ($media): void {
            $this->line('  已删除 '.$media->path);
        });

        Log::warning('媒体回收执行：物理删除 '.$deleted.' 个文件', ['days' => $days]);
        $this->info("已物理删除 {$deleted} 个文件。");

        return self::SUCCESS;
    }

    /**
     * 默认路径：只列清单 + 写通知日志，一个字节都不删
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\MediaFile>  $due
     */
    private function notifyOnly($due, int $days): int
    {
        $this->warn("有 {$due->count()} 张图片已软删满 {$days} 天且无引用：");

        foreach ($due as $media) {
            $this->line(sprintf(
                '  #%d %s（%s，模块 %s，软删于 %s）',
                $media->id,
                $media->path,
                $this->humanSize((int) $media->size),
                $media->module,
                $media->deleted_at?->toDateString(),
            ));
        }

        Log::info('媒体回收通知', [
            'count' => $due->count(),
            'days' => $days,
            'paths' => $due->pluck('path')->all(),
        ]);

        $this->newLine();
        $this->info('未执行删除。如需真删请显式执行：php artisan media:prune --force');

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1).'MB'
            : max(1, (int) round($bytes / 1024)).'KB';
    }
}
