<?php

namespace App\Jobs\Wms;

use App\Models\ReturnInboundOrder;
use App\Services\Wms\Dto\WmsResult;
use App\Services\Wms\WmsAdapterFactory;
use App\Services\Wms\WmsApiLogService;
use App\Services\Wms\WmsConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 取消 WMS 退货入库单（WMS 计划 P4 / F4）
 *
 * 与 {@see CancelOutboundJob} 对称：本地取消已完成（入库单置 `cancelled`），
 * 本作业只是「补一刀」通知 WMS 撤单。失败**不重试**（tries=1），记 `wms_api_logs`
 * 供排查即可——退款单停在 approved 等人工，反复重试没有意义。
 */
class CancelReturnInboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $returnInboundOrderId,
        public readonly string $reason = '',
    ) {}

    public function handle(): void
    {
        $rio = ReturnInboundOrder::find($this->returnInboundOrderId);
        if (! $rio) {
            return;
        }

        $configs = app(WmsConfigService::class);
        $config = $configs->getForWarehouse((int) $rio->warehouse_id);
        if (! $config) {
            return;
        }

        $apiLogs = app(WmsApiLogService::class);
        $started = microtime(true);

        try {
            $result = app(WmsAdapterFactory::class)
                ->make($config)
                ->cancelReturnInbound((string) $rio->inbound_no);
        } catch (\Throwable $e) {
            $result = WmsResult::fail($e->getMessage());
        }

        $apiLogs->record(
            $config,
            'cancelReturnInbound',
            $result,
            $apiLogs->elapsedMs($started),
            $rio->push_request_id,
            (string) $rio->inbound_no,
            [
                'biz_no' => $rio->inbound_no,
                'wms_inbound_no' => $rio->wms_inbound_no,
                'reason' => $this->reason,
            ],
        );

        if (! $result->success) {
            // 本地已是 cancelled（终态），不能也不该改回状态——只把失败详情落 extend + api_logs
            $extend = $rio->extend ?? [];
            $extend['cancel_return_inbound'] = [
                'success' => false,
                'error' => (string) ($result->error ?: '取消退货入库单失败'),
                'at' => now()->toDateTimeString(),
            ];
            $rio->forceFill(['extend' => $extend])->save();
        }
    }
}
