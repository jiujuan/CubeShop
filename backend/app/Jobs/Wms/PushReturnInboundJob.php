<?php

namespace App\Jobs\Wms;

use App\Exceptions\BusinessException;
use App\Models\ReturnInboundOrder;
use App\Models\WmsConfig;
use App\Services\Wms\Dto\ReturnInboundDto;
use App\Services\Wms\Dto\WmsResult;
use App\Services\Wms\ReturnInboundOrderService;
use App\Services\Wms\WmsAdapterFactory;
use App\Services\Wms\WmsApiLogService;
use App\Services\Wms\WmsConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 退货入库单推送作业（WMS 计划 P4 / F4、Step 4）
 *
 * 与 {@see PushOutboundJob} 完全对称：
 * - `pending_push → pushing →（Adapter）→ pushed`；失败按 `retryable` 分流：
 *   可重试交队列退避重试，不可重试直接 `push_failed` 转人工；
 * - 幂等：入库单已是 `pushed/receiving/received/completed/cancelled` 时直接返回；
 *   `request_id` 重试沿用；对方回「单据已存在」按幂等成功照常置 `pushed`；
 * - 推送报文一律落 `wms_api_logs`（脱敏）。
 */
class PushReturnInboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 重试上限兜底（配置可小不可大，避免打爆对方网关） */
    public const MAX_TRIES = 10;

    public int $tries;

    /** 指数退避：10s → 60s → 300s */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $returnInboundOrderId, int $tries = 3)
    {
        $this->tries = max(1, min($tries, self::MAX_TRIES));
    }

    public function handle(): void
    {
        $returns = app(ReturnInboundOrderService::class);
        $configs = app(WmsConfigService::class);
        $factory = app(WmsAdapterFactory::class);
        $apiLogs = app(WmsApiLogService::class);

        $rio = ReturnInboundOrder::with('items')->find($this->returnInboundOrderId);
        if (! $rio) {
            return;
        }

        // 终态或已推送：重复投递直接结束（幂等）
        if (in_array($rio->status, [
            ReturnInboundOrder::STATUS_PUSHED,
            ReturnInboundOrder::STATUS_RECEIVING,
            ReturnInboundOrder::STATUS_RECEIVED,
            ReturnInboundOrder::STATUS_COMPLETED,
            ReturnInboundOrder::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $config = $configs->getForWarehouse((int) $rio->warehouse_id);
        if (! $config) {
            if ($rio->status !== ReturnInboundOrder::STATUS_PENDING_PUSH) {
                $rio = $returns->markPendingPush($rio);
            }
            $returns->markPushFailed($rio, '该仓库未配置 WMS');

            return;
        }

        // 归一到「待推送」再进「推送中」：created（未开自动推送）/ pushing（重试重入）/
        // push_failed / exception（补映射后重推）都能重新走一遍完整流程
        if ($rio->status !== ReturnInboundOrder::STATUS_PENDING_PUSH) {
            $rio = $returns->markPendingPush($rio);
        }

        // 幂等键：重试沿用同一个，避免 WMS 侧重复建单
        $requestId = $rio->push_request_id ?: (string) Str::uuid();
        $rio = $returns->markPushing($rio, $requestId);

        $dto = $this->buildDto($rio);

        $started = microtime(true);
        try {
            $adapter = $factory->make($config);
            $result = $adapter->createReturnInbound($dto);
        } catch (BusinessException $e) {
            // 我方问题（缺凭证/缺仓库货主编码/缺 SKU 映射）：重试无意义，直接判失败转人工
            $result = WmsResult::fail($e->getMessage(), null, [], 0, retryable: false);
        } catch (\Throwable $e) {
            // 工厂解析异常等未预期故障：先按可重试处理，超限后人工介入
            $result = WmsResult::fail($e->getMessage());
        }
        $duration = $apiLogs->elapsedMs($started);

        $apiLogs->record(
            $config,
            'createReturnInbound',
            $result,
            $duration,
            $requestId,
            $rio->inbound_no,
            $dto->toArray(),
        );

        if ($result->success) {
            // 含「对方回单据已存在」的幂等命中：记下 WMS 单号即可，绝不再建第二张
            $returns->markPushed($rio, $this->extractWmsNo($result));

            return;
        }

        $error = (string) ($result->error ?: '未知错误');
        $returns->recordPushFailure($rio, $error);

        // 转人工的两种情形：不可重试（业务终局/我方数据问题）或已达重试上限
        if (! $result->retryable || (int) $rio->fresh()->push_times >= $this->tries) {
            $returns->markPushFailed($rio, $error);

            return;
        }

        throw new RuntimeException("WMS 退货入库单推送失败：{$error}");
    }

    /** 队列判定彻底失败后的兜底（如进程中异常退出、超时） */
    public function failed(\Throwable $e): void
    {
        $rio = ReturnInboundOrder::find($this->returnInboundOrderId);
        if (! $rio || $rio->status === ReturnInboundOrder::STATUS_CANCELLED) {
            return;
        }

        app(ReturnInboundOrderService::class)->markPushFailed($rio, $e->getMessage());
    }

    /** 组装退货入库推送报文（§7.4） */
    private function buildDto(ReturnInboundOrder $rio): ReturnInboundDto
    {
        $items = $rio->items->map(fn ($item) => [
            'sku_code' => (string) $item->platform_sku_code,
            'wms_sku_code' => (string) $item->wms_sku_code,
            'quantity' => (int) $item->qty,
            'product_name' => $item->product_name,
            'barcode' => $item->barcode,
        ])->all();

        return new ReturnInboundDto(
            warehouseId: (int) $rio->warehouse_id,
            bizNo: (string) $rio->inbound_no,
            items: $items,
            refundNo: (string) $rio->refund_no,
            orderNo: $rio->order_no,
            returnReason: $rio->return_reason,
        );
    }

    /** 从回执里取 WMS 侧单号（不同服务商字段名不同，这里做一次归一） */
    private function extractWmsNo(WmsResult $result): ?string
    {
        foreach (['wms_order_no', 'wms_inbound_no', 'order_no'] as $key) {
            if (! empty($result->data[$key])) {
                return (string) $result->data[$key];
            }
        }

        return null;
    }
}
