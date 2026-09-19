<?php

namespace App\Services\Wms\Callback;

/**
 * 回调报文解析器（WMS 计划 P3 / Step 4）
 *
 * 把奇门推送报文归一为统一结构，**只做纯解析、不触库**（可单测）。
 *
 * 归一输出：
 * ```
 * [
 *   'msg_type'   => 'confirm'|'status'|null,
 *   'raw_status' => string|null,           // 原始状态值（SHIPPED/PICKING/…）
 *   'status_key' => string,                // 归一状态键（小写），幂等与路由用
 *   'biz_no'     => string|null,           // 我方出库单号（deliveryOrderCode）
 *   'wms_no'     => string|null,           // 仓方单号（deliveryOrderId）
 *   'order'      => array<string,mixed>,   // deliveryOrder 节点原文
 *   'packages'   => list<array>,           // 归一包裹 [{carrier_code,carrier_name,tracking_no,weight,items}]
 * ]
 * ```
 *
 * 兼容点（奇门推送格式各网关略有差异，全部做容错）：
 * - 信封：`deliveryOrder` 节点可能平铺在根层、包在 `body` 里、或包在业务键下；
 * - 包裹：`packages.package[]` 列表 / 单对象 / 顶层 logisticsCode 三种形态；
 * - 行项目：`items.item[]` 列表 / 单对象 / 缺省。
 */
class CallbackMessageParser
{
    /** @return array<string, mixed> 归一结构（见类注释） */
    public function parse(array $payload): array
    {
        $order = $this->locateOrderNode($payload);

        $method = strtolower((string) ($payload['method'] ?? ''));
        $rawStatus = strtoupper((string) ($order['status'] ?? $payload['status'] ?? ''));

        // msg_type 优先按 method 判定；method 缺失时按状态兜底（confirm 的 status 是 SHIPPED）
        $msgType = match (true) {
            str_contains($method, 'deliveryorder.confirm') => 'confirm',
            str_contains($method, 'deliveryorder') && str_contains($method, 'status') => 'status',
            str_contains($method, 'deliveryorder.pick') => 'status',
            str_contains($method, 'deliveryorder.pack') => 'status',
            $rawStatus === 'SHIPPED' => 'confirm',
            $rawStatus !== '' => 'status',
            default => null,
        };

        // 顶层状态字段（status 推送常平铺在根层）
        if ($rawStatus === '' && isset($payload['status'])) {
            $rawStatus = strtoupper((string) $payload['status']);
        }

        $packages = $this->extractPackages($order, $payload);

        return [
            'msg_type' => $msgType,
            'raw_status' => $rawStatus !== '' ? $rawStatus : null,
            'status_key' => strtolower($rawStatus),
            'biz_no' => $this->stringOrNull($order['deliveryOrderCode'] ?? $payload['deliveryOrderCode'] ?? null),
            'wms_no' => $this->stringOrNull($order['deliveryOrderId'] ?? $payload['deliveryOrderId'] ?? null),
            'order' => $order,
            'packages' => $packages,
        ];
    }

    /**
     * 定位 deliveryOrder 节点（大小写不敏感、三层容错）。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function locateOrderNode(array $payload): array
    {
        // 1) 常见包裹键
        foreach (['deliveryOrder', 'delivery_order', 'body.deliveryOrder'] as $path) {
            $node = data_get($payload, $path);
            if (is_array($node)) {
                return $node;
            }
        }

        // 2) 根层平铺（status 推送常见）：根层直接带 deliveryOrderCode / status
        if (isset($payload['deliveryOrderCode']) || isset($payload['status'])) {
            return $payload;
        }

        // 3) 根层唯一业务键包裹式
        foreach ($payload as $key => $value) {
            if (is_array($value) && isset($value['deliveryOrderCode'], $value['status'])) {
                return $value;
            }
        }

        return [];
    }

    /**
     * 归一包裹列表（三种形态 → 统一列表）。
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractPackages(array $order, array $payload): array
    {
        $raw = $order['packages']['package']
            ?? $order['packages']
            ?? $payload['packages']['package']
            ?? $payload['packages']
            ?? null;

        if (is_array($raw) && array_is_list($raw)) {
            $list = $raw;
        } elseif (is_array($raw)) {
            $list = [$raw];
        } else {
            // 顶层平铺（confirm 单包裹简式）
            $list = [];
            if (isset($order['expressCode']) || isset($payload['expressCode'])) {
                $list[] = $order + $payload;
            }
        }

        $packages = [];
        foreach ($list as $index => $pkg) {
            if (! is_array($pkg)) {
                continue;
            }

            $itemsRaw = $pkg['items']['item'] ?? $pkg['items'] ?? null;
            $items = [];
            if (is_array($itemsRaw)) {
                $rows = array_is_list($itemsRaw) ? $itemsRaw : [$itemsRaw];
                foreach ($rows as $item) {
                    if (is_array($item)) {
                        $items[] = [
                            'platform_sku_code' => $this->stringOrNull($item['itemCode'] ?? $item['platformSkuCode'] ?? null),
                            'wms_sku_code' => $this->stringOrNull($item['warehouseCode'] ?? $item['wmsSkuCode'] ?? null),
                            'item_name' => $this->stringOrNull($item['itemName'] ?? null),
                            'quantity' => (int) ($item['quantity'] ?? $item['itemQuantity'] ?? 0),
                        ];
                    }
                }
            }

            $packages[] = [
                'carrier_code' => $this->stringOrNull($pkg['logisticsCode'] ?? $pkg['carrierCode'] ?? null),
                'carrier_name' => $this->stringOrNull($pkg['logisticsName'] ?? $pkg['expressCompanyName'] ?? null),
                'tracking_no' => $this->stringOrNull($pkg['expressCode'] ?? $pkg['trackingNo'] ?? null),
                'weight' => isset($pkg['weight']) && is_numeric($pkg['weight']) ? (string) $pkg['weight'] : null,
                'items' => $items,
                'sort' => $index,
            ];
        }

        return $packages;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
