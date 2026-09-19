<?php

namespace App\Jobs\Wms;

use App\Models\FulfillmentOrder;
use App\Models\WmsConfig;
use App\Services\Wms\Dto\OutboundDto;
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
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 出库单推送作业（WMS 计划 P1 / F5、Step 6）
 *
 * 流程：`pending_push → pushing →（Adapter）→ pushed`；失败累加 `push_times` 与
 * `last_push_error`，未超限抛异常交给队列退避重试，达到 `tries` 后置 `push_failed`
 * 转人工（后台可「重推」）。
 *
 * 幂等：
 * - 发货单已是 `pushed/shipped/completed/cancelled` 时直接返回——重复投递不会产生第二张外部单据；
 * - `request_id` 存于发货单（`push_request_id`），重试沿用同一幂等键（P2 真实网关据此去重）。
 *
 * 推送报文一律落 `wms_api_logs`（脱敏），是排查「发出去没有、对方回什么」的唯一依据。
 */
class PushOutboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 重试上限兜底（配置可小不可大，避免打爆对方网关） */
    public const MAX_TRIES = 10;

    public int $tries;

    /** 指数退避：10s → 60s → 300s */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $fulfillmentOrderId, int $tries = 3)
    {
        $this->tries = max(1, min($tries, self::MAX_TRIES));
    }

    public function handle(): void
    {
        $fulfillments = app(FulfillmentOrderService::class);
        $configs = app(WmsConfigService::class);
        $factory = app(WmsAdapterFactory::class);
        $apiLogs = app(WmsApiLogService::class);

        $fo = FulfillmentOrder::with('items')->find($this->fulfillmentOrderId);
        if (! $fo) {
            return;
        }

        // 终态或已推送：重复投递直接结束（幂等）
        if (in_array($fo->status, [
            FulfillmentOrder::STATUS_PUSHED,
            FulfillmentOrder::STATUS_SHIPPED,
            FulfillmentOrder::STATUS_COMPLETED,
            FulfillmentOrder::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $config = $configs->getForWarehouse((int) $fo->warehouse_id);
        if (! $config) {
            if ($fo->status !== FulfillmentOrder::STATUS_PENDING_PUSH) {
                $fo = $fulfillments->markPendingPush($fo);
            }
            $fulfillments->markPushFailed($fo, '该仓库未配置 WMS');

            return;
        }

        // 归一到「待推送」再进「推送中」：created（未开自动推送）/ pushing（上次推送中断，
        // 重试重入）/ push_failed / exception（补映射后重推）都能重新走一遍完整流程。
        if ($fo->status !== FulfillmentOrder::STATUS_PENDING_PUSH) {
            $fo = $fulfillments->markPendingPush($fo);
        }

        // 幂等键：重试沿用同一个，避免 WMS 侧重复建单
        $requestId = $fo->push_request_id ?: (string) Str::uuid();
        $fo = $fulfillments->markPushing($fo, $requestId);

        $dto = $this->buildDto($fo, $config);

        $started = microtime(true);
        try {
            $adapter = $factory->make($config);
            $result = $adapter->createOutbound($dto);
        } catch (\Throwable $e) {
            // 工厂/凭证类错误也转成结果对象，统一走下面的留痕与重试判定
            $result = WmsResult::fail($e->getMessage());
        }
        $duration = $apiLogs->elapsedMs($started);

        $apiLogs->record(
            $config,
            'createOutbound',
            $result,
            $duration,
            $requestId,
            $fo->outbound_no,
            $dto->toArray(),
        );

        if ($result->success) {
            $fulfillments->markPushed($fo, $this->extractWmsNo($result));

            return;
        }

        $error = (string) ($result->error ?: '未知错误');
        $fulfillments->recordPushFailure($fo, $error);

        // 达到重试上限：置 push_failed 转人工（不再抛异常，避免队列无限重投）
        if ((int) $fo->fresh()->push_times >= $this->tries) {
            $fulfillments->markPushFailed($fo, $error);

            return;
        }

        throw new RuntimeException("WMS 出库单推送失败：{$error}");
    }

    /** 队列判定彻底失败后的兜底（如进程中异常退出、超时） */
    public function failed(\Throwable $e): void
    {
        $fo = FulfillmentOrder::find($this->fulfillmentOrderId);
        if (! $fo || $fo->status === FulfillmentOrder::STATUS_CANCELLED) {
            return;
        }

        app(FulfillmentOrderService::class)->markPushFailed($fo, $e->getMessage());
    }

    /** 组装出库推送报文（收货人取自建单快照，与 WMS 侧无关） */
    private function buildDto(FulfillmentOrder $fo, WmsConfig $config): OutboundDto
    {
        $items = $fo->items->map(fn ($item) => [
            'sku_code' => (string) $item->platform_sku_code,
            'wms_sku_code' => (string) $item->wms_sku_code,
            'quantity' => (int) $item->qty,
            'barcode' => $item->barcode,
        ])->all();

        $buyer = $fo->buyer_info ?? [];
        $shipping = $fo->shipping_info ?? [];

        return new OutboundDto(
            warehouseId: (int) $fo->warehouse_id,
            bizNo: (string) $fo->outbound_no,
            items: $items,
            receiverName: $buyer['contact_name'] ?? null,
            receiverPhone: $buyer['contact_phone'] ?? null,
            receiverAddress: $buyer['full_address'] ?? null,
            remark: $shipping['remark'] ?? $config->remark,
        );
    }

    /** 从回执里取 WMS 侧单号（不同服务商字段名不同，这里做一次归一） */
    private function extractWmsNo(WmsResult $result): ?string
    {
        foreach (['wms_order_no', 'wms_outbound_no', 'order_no'] as $key) {
            if (! empty($result->data[$key])) {
                return (string) $result->data[$key];
            }
        }

        return null;
    }
}
