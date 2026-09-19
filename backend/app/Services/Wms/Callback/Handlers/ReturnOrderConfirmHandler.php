<?php

namespace App\Services\Wms\Callback\Handlers;

use App\Exceptions\Wms\WmsBizException;
use App\Models\ReturnInboundOrder;
use App\Services\Wms\ReturnInboundOrderService;

/**
 * 退货入库确认回传（奇门 returnorder.confirm，WMS 计划 P4 / F5、Step 6）
 *
 * 语义：WMS 已完成退货收货质检 → 逐行落 `received_qty` / `inventory_type`（ZP 正品 /
 * CC 残次）→ 复用 {@see ReturnInboundOrderService::complete()} 恢复库存并完成退款。
 *
 * 关键规则：
 * - **先收货后退款**：complete() 是唯一推进退款 success 的入口（D-P4-1）；
 * - **不做破坏性操作**（计划 Step 6）：实收 0 / 超收 → 转异常保留报文，不回库存不放款；
 * - 幂等：入库单已 received/completed 时直接忽略（重复回传被业务吸收，状态机终态）。
 */
class ReturnOrderConfirmHandler
{
    public function __construct(private readonly ReturnInboundOrderService $returns)
    {
    }

    public function supports(string $msgType): bool
    {
        return $msgType === 'returnorder_confirm';
    }

    public function handle(ReturnInboundOrder $rio, array $message, array $ctx = []): void
    {
        // 幂等短路：已收货/已完成（或已取消的单据回传迟到了），重推无意义
        if (in_array($rio->status, [
            ReturnInboundOrder::STATUS_RECEIVED,
            ReturnInboundOrder::STATUS_COMPLETED,
            ReturnInboundOrder::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        if (! in_array($rio->status, [
            ReturnInboundOrder::STATUS_PUSHED,
            ReturnInboundOrder::STATUS_RECEIVING,
        ], true)) {
            throw new WmsBizException(sprintf(
                '退货入库单当前状态「%s」不允许处理收货回传',
                $rio->statusLabel(),
            ));
        }

        $this->returns->markReceived(
            $rio,
            $this->extractLines($message),
            operatorId: null,
        );
    }

    /**
     * 从归一消息提取回传明细行（兼容 `orderLines.orderLine[]` / 列表 / 单对象三种形态）。
     *
     * @return list<array<string, mixed>>  [{platform_sku_code, quantity, inventory_type}]
     */
    private function extractLines(array $message): array
    {
        $order = (array) ($message['order'] ?? []);
        $raw = $order['orderLines']['orderLine']
            ?? $order['orderLines']
            ?? $message['payload']['orderLines']['orderLine']
            ?? $message['payload']['orderLines']
            ?? [];

        if (is_array($raw) && ! array_is_list($raw)) {
            $raw = [$raw];
        }

        $lines = [];
        foreach ((array) $raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['itemCode'] ?? $row['item_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $lines[] = [
                'platform_sku_code' => $code,
                'quantity' => (int) ($row['actualQty'] ?? $row['quantity'] ?? 0),
                'inventory_type' => (string) ($row['inventoryType'] ?? ReturnInboundOrder::INVENTORY_TYPE_GOOD),
            ];
        }

        return $lines;
    }
}
