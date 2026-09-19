<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\WmsApiLog;

/**
 * 单据详情的「最近调用流水」（WMS 计划 P6 / F2、F4）
 *
 * 详情页只给摘要（最近 5 条，不含报文），完整报文去日志页按 `request_id` 查：
 * 既保证「看单据就能知道最近推了几次、错在哪」，又避免详情页塞进 KB 级报文。
 *
 * 匹配口径：`biz_no` 精确命中；单据号缺失时退化为 `push_request_id` 命中
 * （推送流水在单据落 request_id 之前就已写入日志）。
 */
trait WmsLogSummary
{
    /** @return list<array<string, mixed>> */
    protected function recentLogs(?string $bizNo, ?string $requestId = null, int $limit = 5): array
    {
        if ($bizNo === null || $bizNo === '') {
            return [];
        }

        return WmsApiLog::query()
            ->where('biz_no', $bizNo)
            ->when($requestId, fn ($q, $rid) => $q->orWhere('request_id', $rid))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (WmsApiLog $log) => [
                'id' => $log->id,
                'direction' => $log->direction,
                'direction_label' => $log->direction === WmsApiLog::DIRECTION_OUTBOUND ? '出站' : '入站',
                'api_name' => $log->api_name,
                'request_id' => $log->request_id,
                'success' => (bool) $log->success,
                'error_msg' => $log->error_msg,
                'http_status' => $log->http_status,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])
            ->values()
            ->all();
    }
}
