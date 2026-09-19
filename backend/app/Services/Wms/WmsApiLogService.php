<?php

namespace App\Services\Wms;

use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Services\Wms\Dto\WmsResult;
use App\Services\Wms\Support\PayloadMasker;

/**
 * WMS 报文留痕（WMS 计划 P0 决策延续：留痕统一在编排层，Adapter 保持纯粹）
 *
 * 真实 Adapter / Mock / 抛错三种情况一视同仁地落 `wms_api_logs`，
 * 是排查「到底发出去没有、对方回了什么」的唯一依据。
 *
 * **脱敏是本服务的职责，不由调用方承担**（P2 / F8）：入参与回执都先过
 * {@see PayloadMasker} 再落库。放在这里而不是各调用点，才能保证
 * 「任何一条路径写日志都脱敏」——调用方想忘也忘不掉。
 *
 * 留痕失败**绝不影响主流程**：这里只 report，不抛。
 */
class WmsApiLogService
{
    public function __construct(private readonly PayloadMasker $masker) {}

    /**
     * @param  array<string, mixed>  $requestBody  入参（内部会脱敏；凭证不得入库）
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
                'request_body' => $this->masker->mask($requestBody),
                // raw 里含对方完整回执（可能有收件人手机号），一并脱敏
                'response_body' => $this->masker->mask($result->toArray() + ['raw' => $result->raw]),
                'http_status' => $result->httpStatus,
                // P7 联调发现项（D-P7-3）：本参数此前收了却没落库（表里也没这列），
                // 现在列已补齐，出站/入站统一按毫秒记录，性能基线与排障都靠它。
                'duration_ms' => $durationMs,
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
