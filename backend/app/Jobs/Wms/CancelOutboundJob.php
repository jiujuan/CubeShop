<?php

namespace App\Jobs\Wms;

use App\Exceptions\Wms\WmsBizException;
use App\Models\FulfillmentOrder;
use App\Services\Wms\Dto\CancelOutboundDto;
use App\Services\Wms\Dto\WmsResult;
use App\Services\Wms\FulfillmentOrderService;
use App\Services\Wms\WmsAdapterFactory;
use App\Services\Wms\WmsApiLogService;
use App\Services\Wms\WmsConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 取消 WMS 出库单（WMS 计划 P1 / F7；P2 接通真实菜鸟）
 *
 * 本地取消已完成（发货单置 `cancelled`），本作业只是「补一刀」通知 WMS 撤单。
 * 因此失败**不重试**（`tries = 1`）：撤单失败属于对方需人工处理的场景，
 * 记 `wms_api_logs` 供排查即可，反复重试没有意义也打扰对方系统。
 *
 * P2 新增：仓方以「已出库/状态不允许」拒绝撤单时（{@see WmsBizException}），
 * 该履约单会被标记为**需人工介入**（`FulfillmentOrderService::recordCancelFailure()`）——
 * 因为此时货可能已在路上，而平台侧订单已经取消了，这是必须被人看到的冲突。
 * 无论哪种失败，都只记一条 `wms_api_logs`，不做二次留痕。
 */
class CancelOutboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $fulfillmentOrderId,
        public readonly string $reason = '',
    ) {}

    public function handle(): void
    {
        $fo = FulfillmentOrder::find($this->fulfillmentOrderId);
        if (! $fo) {
            return;
        }

        $configs = app(WmsConfigService::class);
        $config = $configs->getForWarehouse((int) $fo->warehouse_id);
        if (! $config) {
            return;
        }

        $apiLogs = app(WmsApiLogService::class);
        $started = microtime(true);
        $bizCode = null;

        try {
            $result = app(WmsAdapterFactory::class)
                ->make($config)
                ->cancelOutbound(new CancelOutboundDto(
                    warehouseId: (int) $fo->warehouse_id,
                    bizNo: (string) $fo->outbound_no,
                    wmsOutboundNo: $fo->wms_outbound_no,
                    reason: $this->reason,
                ));
        } catch (WmsBizException $e) {
            // 仓方明确拒绝（多为「已出库」）：不是故障，是业务终局
            $bizCode = $e->wmsCode;
            $result = WmsResult::fail($e->getMessage(), null, ['payload' => $e->payload], 0, retryable: false);
        } catch (\Throwable $e) {
            $result = WmsResult::fail($e->getMessage());
        }

        $apiLogs->record(
            $config,
            'cancelOutbound',
            $result,
            $apiLogs->elapsedMs($started),
            $fo->push_request_id,
            (string) $fo->outbound_no,
            [
                'biz_no' => $fo->outbound_no,
                'wms_outbound_no' => $fo->wms_outbound_no,
                'reason' => $this->reason,
            ],
        );

        if (! $result->success) {
            app(FulfillmentOrderService::class)->recordCancelFailure(
                $fo,
                (string) ($result->error ?: '取消出库单失败'),
                $bizCode,
            );
        }
    }
}
