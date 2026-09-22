<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Services\Common\MediaRegistry;
use Illuminate\Console\Command;

/**
 * 图片资产扫描（媒体治理 P0）
 *
 * 两件事，一起做：
 * 1. **登记**：把磁盘上的 `uploads/**` 文件补进 `media_files`（上线前存量回填，之后新文件由上传链路登记）；
 * 2. **重算引用**：遍历单值列 / JSON 数组 / 富文本内联 / 系统配置四类来源，刷新 `usage_count`。
 *
 * 用法：
 * - `php artisan media:scan`                登记 + 重算
 * - `php artisan media:scan --no-register`  只重算引用计数
 *
 * ⚠️ 扫描只登记 / 计数，**不做任何删除** —— 删除永远由 `media:prune` 显式执行。
 */
class MediaScan extends Command
{
    protected $signature = 'media:scan
        {--no-register : 跳过磁盘文件的存量登记，只重算引用计数}';

    protected $description = '扫描磁盘图片并登记到 media_files，同时重算各图的引用计数 usage_count';

    public function handle(MediaRegistry $registry): int
    {
        $registered = 0;

        if (! $this->option('no-register')) {
            foreach ($registry->diskFiles() as $path) {
                if (MediaFile::withTrashed()->where('path', $path)->exists()) {
                    continue;
                }

                if ($registry->registerExisting($path) !== null) {
                    $registered++;
                }
            }

            $this->info("磁盘登记：新增 {$registered} 条");
        }

        $counts = $registry->recomputeUsage();

        $total = count($counts);
        $unused = collect($counts)->filter(fn (int $c): bool => $c === 0)->count();

        $this->info("引用重算：{$total} 张图有引用记录，其中 {$unused} 张当前引用为 0");

        return self::SUCCESS;
    }
}
