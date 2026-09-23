<?php

namespace App\Jobs\Search;

use App\Models\SearchKeyword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 搜索词频自增（站内搜索 S1-06）
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.7
 *
 * 每次「有结果」的搜索投递一次，异步累加 —— 搜索响应里不该有一次写库。
 *
 * 节流在**投递方**（`ProductSearchService`）用 Cache 做，不在本 Job 里做：Job 已经出队时
 * 说明这次计数已通过节流，再判一遍只会让语义混乱。
 *
 * ⚠️ 幂等性：这是计数而非快照，重跑会多算一次。失败重试带来的偏差可接受
 * —— 热搜榜是排序参考，不是财务数据。
 */
class UpdateSearchKeyword implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 10;

    public function __construct(
        public readonly string $keyword,
        public readonly int $resultCount,
    ) {
    }

    public function handle(): void
    {
        if ($this->keyword === '') {
            return;
        }

        $row = SearchKeyword::query()->firstOrNew(['keyword' => $this->keyword]);

        $row->hit_count = (int) ($row->hit_count ?? 0) + 1;
        $row->result_count = $this->resultCount;
        $row->last_hit_at = now();

        // firstOrNew 出来的新行没有默认值，显式给一次；已存在的行（含被屏蔽的）不改状态
        if ($row->status === null) {
            $row->status = SearchKeyword::STATUS_ACTIVE;
        }

        $row->save();
    }
}
