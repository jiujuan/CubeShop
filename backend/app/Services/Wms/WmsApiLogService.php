<?php

namespace App\Services\Wms;

use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Services\Wms\Dto\WmsResult;

/**
 * WMS 报文留痕（WMS 计划 P0 决策延续：留痕统一在编排层，Adapter 保持纯粹）
 *
 * 真实 Adapter / Mock / 抛错三种情况一视同仁地落 `wms_api_logs`，
 * 是排查「到底发出去没有、对方回了什么」的唯一依据。
 *
 * 留痕失败**绝不影响主流程**：这里只 report，不抛。
 */
class WmsApiLogService
{
    /**
     * @param  array<string, mixed>  $requestBody  入参（**必须已脱敏**，凭证不得入库）
     */
    public function record(
        WmsConfig $config,
        string $apiName,
        WmsResult $result,
        int $durationMs,
        ?string $requestId = null,
        ?string $bizNo = null,
        array $requestBody = [],
        string $direction = WmsApiLog::DIRECTION_OUTBOUND,
    ): void {
        try {
            WmsApiLog::create([
                'direction' => $direction,
                'provider' => (string) $config->provider,
                'api_name' => $apiName,
                'request_id' => $requestId,
                'biz_no' => $bizNo,
                'request_body' => $requestBody,
                'response_body' => $result->toArray(),
                'http_status' => $result->httpStatus,
                'success' => $result->success,
                'error_msg' => $result->error,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 已耗时（毫秒） */
    public function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
