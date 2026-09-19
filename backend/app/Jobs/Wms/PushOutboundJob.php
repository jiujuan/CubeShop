<?php

namespace App\Jobs\Wms;

use App\Exceptions\BusinessException;
use App\Models\FulfillmentOrder;
use App\Models\WmsConfig;
use App\Models\WmsSkuMapping;
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
 * - `request_id` 存于发货单（`push_request_id`），重试沿用同一幂等键；
 * - 对方回「单据已存在」时 Adapter 判定为**幂等成功**（`WmsResult::idempotent`），
 *   本作业照常置 `pushed`——这是重试场景下最常发生、也最怕处理错的一类回执。
 *
 * 失败处置（P2 起按 Adapter 给出的语义分流，不再一律重试）：
 * - `retryable = true`（网络抖动、对方 5xx）→ 抛异常交队列退避重试，`tries` 次后置 `push_failed`；
 * - `retryable = false`（对方业务校验失败、我方配置/数据有误）→ **立即**置 `push_failed`
 *   转人工，避免明知无用还反复打扰对方系统。
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

        // 推送前按最新映射重算 SKU 货品编码（P7 联调发现项）：
        // 发货单建单时若缺映射会转异常并落空编码，补映射后重推必须重新解析，
        // 否则永远卡在「缺编码」无法修复。resolvedSkuCode 缺失会抛 BusinessException，
        // 由下方 catch 转 push_failed——这是「映射仍未配齐」的正确终局。
        $this->refreshItemCodes($fo, $config, $configs);

        $dto = $this->buildDto($fo, $config);

        $started = microtime(true);
        try {
            $adapter = $factory->make($config);
            $result = $adapter->createOutbound($dto);
        } catch (BusinessException $e) {
            // 我方问题（缺凭证/缺仓库货主编码/收件人地址不全/缺 SKU 映射）：
            // 重试一万次也是同样结果，直接判失败转人工，别白打扰对方系统
            $result = WmsResult::fail($e->getMessage(), null, [], 0, retryable: false);
        } catch (\Throwable $e) {
            // 工厂解析异常等未预期故障：先按可重试处理，超限后人工介入
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
            // 含「对方回单据已存在」的幂等命中：业务已达成，记下 WMS 单号即可，绝不再建第二张
            $fulfillments->markPushed($fo, $this->extractWmsNo($result));

            return;
        }

        $error = (string) ($result->error ?: '未知错误');
        $fulfillments->recordPushFailure($fo, $error);

        // 转人工的两种情形：不可重试（业务终局/我方数据问题）或已达重试上限
        if (! $result->retryable || (int) $fo->fresh()->push_times >= $this->tries) {
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
            'product_name' => $item->product_name,
            'barcode' => $item->barcode,
        ])->all();

        $buyer = $fo->buyer_info ?? [];
        $shipping = $fo->shipping_info ?? [];

        // 奇门 receiverInfo 要的是**拆开**的省/市/区，只有一个拼好的完整地址字符串时
        // 无法可靠反推（直辖市/省直管县的切分规则因地区而异），故原样透传快照各字段
        return new OutboundDto(
            warehouseId: (int) $fo->warehouse_id,
            bizNo: (string) $fo->outbound_no,
            items: $items,
            receiverName: $buyer['contact_name'] ?? null,
            receiverPhone: $buyer['contact_phone'] ?? null,
            receiverAddress: $buyer['full_address'] ?? null,
            remark: $shipping['remark'] ?? $config->remark,
            orderNo: (string) $fo->order_no,
            province: $buyer['province'] ?? null,
            city: $buyer['city'] ?? null,
            district: $buyer['district'] ?? null,
            detailAddress: $buyer['detail_address'] ?? null,
        );
    }

    /**
     * 推送前按最新映射重算每个 item 的 WMS 货品编码与条码。
     *
     * 发货单建单时若缺映射会转异常并落空编码；补映射后重推必须重新解析，否则永远卡死。
     * resolvedSkuCode 缺失（映射仍未配齐）会抛 BusinessException，由 handle 的 catch 转 push_failed。
     */
    private function refreshItemCodes(FulfillmentOrder $fo, WmsConfig $config, WmsConfigService $configs): void
    {
        foreach ($fo->items as $item) {
            if (! $item->sku_id) {
                continue;
            }

            $wmsCode = $configs->resolveSkuCode($config, (int) $item->sku_id);
            $barcode = WmsSkuMapping::where('warehouse_id', $config->warehouse_id)
                ->where('sku_id', (int) $item->sku_id)
                ->where('status', 1)
                ->value('barcode');

            if ($item->wms_sku_code !== $wmsCode || $item->barcode !== $barcode) {
                $item->forceFill(['wms_sku_code' => $wmsCode, 'barcode' => $barcode])->save();
            }
        }

        $fo->load('items');
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
