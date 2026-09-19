<?php

namespace App\Services\Wms\Dto;

/**
 * 取消出库单入参（WMS 计划 P2 / F5、设计文档 §7.3）
 *
 * 为什么不能只传一个 `bizNo` 字符串：奇门取消接口的 `deliveryOrderId`
 * 是**条件必填**——菜鸟侧若已受理并分配了出库单号，不带它就可能定位不到单据。
 * 而这个号存在 `fulfillment_orders.wms_outbound_no` 上，Adapter 自己拿不到，
 * 必须由调用方（CancelOutboundJob）带进来。
 */
final class CancelOutboundDto
{
    public function __construct(
        public readonly int $warehouseId,
        /** 平台出库单号 → 奇门 `deliveryOrderCode` */
        public readonly string $bizNo,
        /** WMS 侧出库单号 → 奇门 `deliveryOrderId`（条件必填） */
        public readonly ?string $wmsOutboundNo = null,
        /** 取消原因（写日志，部分仓方会要求） */
        public readonly ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'biz_no' => $this->bizNo,
            'wms_outbound_no' => $this->wmsOutboundNo,
            'reason' => $this->reason,
        ];
    }
}
