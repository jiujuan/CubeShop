<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Search\SearchConfig;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Console\Command;

/**
 * 商品检索索引重建（站内搜索 S1-08）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2 / §4.6
 *
 * 索引列由 `Product::saving` 与级联 Job 维护，但那两条路径都可能漏：
 * 直接改库、批量导入、迁移回填漏行、开关从「关」改「开」—— 都需要一次全量兜底。
 * 本命令就是这个兜底：**幂等、可重复跑、只写变化行**。
 *
 * 用法：
 * - `php artisan search:reindex`                      全量重建
 * - `php artisan search:reindex --ids=1,2,3`          只重建指定商品
 * - `php artisan search:reindex --chunk=200 --sleep=50`  小分块 + 块间休眠，降低对线上库的压力
 * - `php artisan search:reindex --no-bump`            不递增 index_version（保留现有命中缓存）
 *
 * ⚠️ 重建后会**递增 index_version** 让旧缓存自然过期。缓存 key 里没有商品 id，
 * 无法定向失效某几条 —— 要么全清、要么等 TTL，前者更安全（重建是低频运维动作）。
 * 只想刷索引又不想清缓存时用 `--no-bump`。
 */
class SearchReindex extends Command
{
    protected $signature = 'search:reindex
        {--chunk=500 : 每次取多少条}
        {--sleep=0 : 每块之间休眠毫秒，给线上库留口气}
        {--ids= : 只重建指定商品 id（逗号分隔），缺省为全量（含软删）}
        {--no-bump : 重建后不递增 index_version}';

    protected $description = '重建商品检索列 search_title / search_body（幂等，只写变化行）';

    public function handle(SearchIndexWriter $writer, SearchConfig $config): int
    {
        $ids = $this->parseIds();
        $chunk = max(1, (int) $this->option('chunk'));
        $sleep = max(0, (int) $this->option('sleep'));

        $query = Product::query()->withTrashed();

        if ($ids !== null) {
            if ($ids === []) {
                $this->warn('--ids 未解析出有效 id，无事可做');

                return self::SUCCESS;
            }

            $query->whereIn('id', $ids);
        }

        $started = microtime(true);
        $scanned = 0;
        $updated = 0;

        $writer->reindexQuery($query, $chunk, function (int $scannedSoFar, int $updatedSoFar) use (&$scanned, &$updated, $sleep): void {
            $scanned = $scannedSoFar;
            $updated = $updatedSoFar;

            $this->line(sprintf('  已扫描 %d 条，写回 %d 条', $scannedSoFar, $updatedSoFar));

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        });

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        $version = $this->option('no-bump')
            ? $config->indexVersion()
            : $config->bumpIndexVersion();

        $this->info(sprintf(
            '检索索引重建完成：扫描 %d 条，写回 %d 条，耗时 %d ms，index_version=%d',
            $scanned,
            $updated,
            $elapsed,
            $version,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null  null 表示全量
     */
    private function parseIds(): ?array
    {
        $raw = trim((string) $this->option('ids'));

        if ($raw === '') {
            return null;
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
