<?php

namespace App\Services\Wms\Dto;

/**
 * 退货入库单推送入参（WMS 计划 P4 / 设计文档 §7.4）
 *
 * 退货闭环：退款审核通过 → 生成退货入库单 → 推送 WMS → WMS 收货回传 → 本地恢复库存。
 */
final class ReturnInboundDto
{
    /**
     * @param  array<int, array{sku_code: string, wms_sku_code: string, quantity: int, product_name?: string|null, barcode?: string|null}>  $items
     */
    public function __construct(
        public readonly int $warehouseId,
        public readonly string $bizNo,
        public readonly array $items,
        public readonly ?string $refundNo = null,
        public readonly ?string $orderNo = null,
        public readonly ?string $remark = null,
        public readonly ?string $returnReason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'biz_no' => $this->bizNo,
            'items' => $this->items,
            'refund_no' => $this->refundNo,
            'order_no' => $this->orderNo,
            'remark' => $this->remark,
            'return_reason' => $this->returnReason,
        ];
    }
}
