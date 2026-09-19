<?php

namespace App\Services\Wms\Callback\Handlers;

use App\Exceptions\Wms\WmsBizException;
use App\Models\FulfillmentOrder;
use App\Services\Wms\FulfillmentOrderService;

/**
 * 出库单状态回传（奇门 deliveryorder.status 等，WMS 计划 P3 / F2）
 *
 * 支持的状态键（大小写不敏感）：
 * - `PICKING` → 拣货中；`PACKED` → 已打包；
 * - `EXCEPTION`（或缺货/地址异常等异常语义）→ `markException` 转人工；
 * - `SHIPPED` 无包裹明细时降级为 confirm（有包裹明细请走 confirm 处理器）；
 * - 其余状态（如 CANCELED）→ 保守忽略并抛说明性异常（不静默吞，便于联调发现）。
 */
class DeliveryOrderStatusHandler implements CallbackHandler
{
    public function __construct(private readonly FulfillmentOrderService $fulfillments)
    {
    }

    public function supports(string $msgType): bool
    {
        return $msgType === 'status';
    }

    public function handle(FulfillmentOrder $fo, array $message, array $ctx = []): void
    {
        $statusKey = strtolower((string) ($message['status_key'] ?? ''));
        $detail = trim((string) ($message['order']['statusDetail'] ?? $message['order']['remark'] ?? ''));

        switch ($statusKey) {
            case 'picking':
                $this->fulfillments->markPicking($fo);
                return;

            case 'packed':
                $this->fulfillments->markPacked($fo);
                return;

            case 'exception':
            case 'out_of_stock':
            case 'address_error':
                $this->fulfillments->markException($fo, $detail !== '' ? $detail : "WMS 回传异常状态：{$message['raw_status']}");
                return;

            case 'shipped':
                // 简式发货回传（无包裹）：packages 已由解析器兜底为空，转 confirm 处理器语义
                if (($message['packages'] ?? []) === []) {
                    throw new WmsBizException('SHIPPED 状态回传缺少运单号，请走 deliveryorder.confirm');
                }
                throw new WmsBizException('SHIPPED 状态应携带包裹明细走 confirm 处理器');

            default:
                throw new WmsBizException("忽略未知履约状态回传：{$message['raw_status']}");
        }
    }
}
