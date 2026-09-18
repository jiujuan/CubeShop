<?php

namespace App\Services\Wms\Dto;

/**
 * 出库单推送入参（WMS 计划 P0 占位；P2 创建出库单消费）
 *
 * 只描述「推什么」，不含签名/网关细节——那些由具体 Adapter 负责。
 */
final class OutboundDto
{
    /**
     * @param  array<int, array{sku_code: string, wms_sku_code: string, quantity: int, barcode?: string|null}>  $items
     */
    public function __construct(
        public readonly int $warehouseId,
        public readonly string $bizNo,
        public readonly array $items,
        public readonly ?string $receiverName = null,
        public readonly ?string $receiverPhone = null,
        public readonly ?string $receiverAddress = null,
        public readonly ?string $remark = null,
    ) {}

    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'biz_no' => $this->bizNo,
            'items' => $this->items,
            'receiver_name' => $this->receiverName,
            'receiver_phone' => $this->receiverPhone,
            'receiver_address' => $this->receiverAddress,
            'remark' => $this->remark,
        ];
    }
}
