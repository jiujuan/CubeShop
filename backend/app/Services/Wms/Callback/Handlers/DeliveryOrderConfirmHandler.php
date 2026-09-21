<?php

namespace App\Services\Wms\Callback\Handlers;

use App\Models\FulfillmentOrder;
use App\Models\Shipping;
use App\Models\ShippingPackage;
use App\Services\Wms\FulfillmentOrderService;
use App\Support\CarrierCode;
use App\Support\WmsProvider;
use Illuminate\Support\Facades\Log;

/**
 * 出库单确认收货回传（奇门 deliveryorder.confirm，WMS 计划 P3 / F1）
 *
 * 语义：WMS 已完成出库并交运 → 订单发货 + 全量包裹落档。
 *
 * 关键规则：
 * - **订单发货唯一入口** `OrderService::shipForShipment()`（经 markShipped），绝不另写订单状态；
 * - 主表 `shippings` 只存首包裹（订单展示链路零变化），全量包裹落 `shipping_packages`；
 * - 幂等：markShipped 同运单号幂等；若已发货但**运单号不一致** → 视为数据冲突，
 *   落告警并按已消费处理（状态机已终态，重推无意义，且不制造重推风暴）；
 * - **入站归一**：仓方回传的承运商编码先经 `CarrierCode::fromChannel()` 转成平台码再落
 *   `shippings.company_code`（详见 {@see self::normalizeCarrierCode()}）；原值仍保留在
 *   `shipping_packages.carrier_code` 供溯源。
 */
class DeliveryOrderConfirmHandler implements CallbackHandler
{
    public function __construct(private readonly FulfillmentOrderService $fulfillments)
    {
    }

    public function supports(string $msgType): bool
    {
        return $msgType === 'confirm';
    }

    public function handle(FulfillmentOrder $fo, array $message, array $ctx = []): void
    {
        $packages = $message['packages'] ?? [];
        if ($packages === [] && ($message['raw_status'] ?? '') === 'SHIPPED') {
            // 无包裹明细的简式回传：以顶层字段兜底成单包裹
            $packages = [[
                'carrier_code' => $message['order']['logisticsCode'] ?? null,
                'carrier_name' => $message['order']['logisticsName'] ?? null,
                'tracking_no' => $message['order']['expressCode'] ?? null,
                'weight' => null,
                'items' => [],
                'sort' => 0,
            ]];
        }

        $first = $packages[0] ?? null;
        $trackingNo = (string) ($first['tracking_no'] ?? '');
        if ($trackingNo === '') {
            throw new \App\Exceptions\Wms\WmsBizException('回传缺少运单号（expressCode），无法确认发货');
        }

        $carrierCode = $this->normalizeCarrierCode((string) ($first['carrier_code'] ?? ''), $fo);
        $carrierName = (string) ($first['carrier_name'] ?? $carrierCode);

        /*
         * 实发数量：跨**全部包裹**按 platform_sku_code 归集，未带 items 的行由 markShipped 默认全量。
         *
         * ⚠️ P7 联调发现项（D-P7-2）：原实现只读首包裹的 items，一单多包裹且货品分散时
         * 会**少算**实发数量（例如 3 件拆 2 个包裹 → 只记 2 件），进而影响退货可退数量
         * 与对账口径。改为逐包裹累加；包裹全无 items 时 shippedQty 仍为空，保留「默认全量」语义。
         */
        $shippedQty = [];
        $byPlatformCode = $fo->items()->get()->keyBy('platform_sku_code');
        foreach ($packages as $package) {
            foreach ((array) ($package['items'] ?? []) as $row) {
                $code = (string) ($row['platform_sku_code'] ?? '');
                $qty = (int) ($row['quantity'] ?? 0);
                if ($code !== '' && $qty > 0 && isset($byPlatformCode[$code])) {
                    $skuId = (int) $byPlatformCode[$code]->sku_id;
                    $shippedQty[$skuId] = ($shippedQty[$skuId] ?? 0) + $qty;
                }
            }
        }

        $this->fulfillments->markShipped($fo, $carrierCode, $carrierName, $trackingNo, $shippedQty);

        $this->persistPackages($fo->fresh(), $packages);
    }

    /**
     * 全量包裹落档（幂等：同 shipping_id + tracking_no 已存在则跳过）。
     *
     * @param  list<array<string, mixed>>  $packages
     */
    private function persistPackages(FulfillmentOrder $fo, array $packages): void
    {
        $shipping = Shipping::query()
            ->where('order_id', $fo->order_id)
            ->orderByDesc('id')
            ->first();

        if (! $shipping) {
            // markShipped 必然创建 Shipping；到不了这里除非外部删数据——保守处理
            return;
        }

        foreach ($packages as $index => $pkg) {
            $trackingNo = (string) ($pkg['tracking_no'] ?? '');
            if ($trackingNo === '') {
                continue;
            }

            ShippingPackage::query()->updateOrCreate(
                [
                    'shipping_id' => $shipping->id,
                    'tracking_no' => $trackingNo,
                ],
                [
                    'carrier_code' => $pkg['carrier_code'] ?? null,
                    'carrier_name' => $pkg['carrier_name'] ?? null,
                    'weight' => $pkg['weight'] ?? null,
                    'items' => $pkg['items'] ?? [],
                    'sort' => (int) ($pkg['sort'] ?? $index),
                ],
            );
        }
    }

    /**
     * 仓方承运商编码 → 平台码（入站归一）。
     *
     * `shippings.company_code` 只允许存平台内部 code —— 快递100 解析、后台筛选、导出都按这个口径。
     * 仓方回传的是它们自己的体系（奇门 `logisticsCode`），原样落库会让同一张表出现
     * `SF` / `shunfeng` / `OTHER` 三种互不兼容的取值，快递100 只能命中一半。
     *
     * 按**单据自身的 provider** 选渠道而非写死 cainiao —— 京东云仓（P8）接入后此处无需改动。
     *
     * 归一失败时**保留原值并告警**，绝不阻断发货：发货本身没问题，错的只是编码；
     * 强行置空会让后续连补救转换的机会都没有。原值同时留在
     * `shipping_packages.carrier_code` 供对账溯源。
     */
    private function normalizeCarrierCode(string $raw, FulfillmentOrder $fo): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $channel = (string) ($fo->provider ?: WmsProvider::CAINIAO);
        $normalized = CarrierCode::fromChannel($raw, $channel);

        if ($normalized !== null) {
            return $normalized;
        }

        Log::warning('[WMS] 回传承运商编码无法归一为平台码', [
            'outbound_no' => $fo->outbound_no,
            'order_id' => $fo->order_id,
            'warehouse_id' => $fo->warehouse_id,
            'provider' => $channel,
            'raw_carrier_code' => $raw,
        ]);

        return $raw;
    }
}
