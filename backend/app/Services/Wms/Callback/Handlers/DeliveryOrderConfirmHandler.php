<?php

namespace App\Services\Wms\Callback\Handlers;

use App\Models\FulfillmentOrder;
use App\Models\Shipping;
use App\Models\ShippingPackage;
use App\Services\Wms\FulfillmentOrderService;

/**
 * 出库单确认收货回传（奇门 deliveryorder.confirm，WMS 计划 P3 / F1）
 *
 * 语义：WMS 已完成出库并交运 → 订单发货 + 全量包裹落档。
 *
 * 关键规则：
 * - **订单发货唯一入口** `OrderService::shipForShipment()`（经 markShipped），绝不另写订单状态；
 * - 主表 `shippings` 只存首包裹（订单展示链路零变化），全量包裹落 `shipping_packages`；
 * - 幂等：markShipped 同运单号幂等；若已发货但**运单号不一致** → 视为数据冲突，
 *   落告警并按已消费处理（状态机已终态，重推无意义，且不制造重推风暴）。
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

        $carrierCode = (string) ($first['carrier_code'] ?? '');
        $carrierName = (string) ($first['carrier_name'] ?? $carrierCode);

        // 实发数量：回传带 items 时按 platform_sku_code 归集，未带的行由 markShipped 默认全量
        $shippedQty = [];
        $items = (array) ($first['items'] ?? []);
        if ($items !== []) {
            $byPlatformCode = $fo->items()->get()->keyBy('platform_sku_code');
            foreach ($items as $row) {
                $code = (string) ($row['platform_sku_code'] ?? '');
                $qty = (int) ($row['quantity'] ?? 0);
                if ($code !== '' && $qty > 0 && isset($byPlatformCode[$code])) {
                    $shippedQty[(int) $byPlatformCode[$code]->sku_id] = $qty;
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
}
