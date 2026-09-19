<?php

namespace App\Services\Wms\Dto;

/**
 * 出库单推送入参（WMS 计划 P0 占位；P2 创建出库单消费）
 *
 * 只描述「推什么」，不含签名/网关细节——那些由具体 Adapter 负责。
 *
 * P2 补齐了奇门报文真正需要的两处信息（原先只有拼好的 `receiverAddress` 字符串）：
 * - `orderNo`：交易订单号（奇门 `orderCode` / `sourceOrderCode`，设计文档 §7.1 必填）；
 * - 结构化省/市/区 + 详细地址：奇门 `receiverInfo` 是**拆开**的四个字段，
 *   只有一个完整地址字符串时无法可靠拆分（"北京市北京市朝阳区"这类直辖市/省直管县
 *   的切分规则因地区而异），所以必须由上游把快照里的原字段带下来。
 */
final class OutboundDto
{
    /**
     * @param  array<int, array{sku_code: string, wms_sku_code: string, quantity: int, product_name?: string|null, barcode?: string|null}>  $items
     */
    public function __construct(
        public readonly int $warehouseId,
        public readonly string $bizNo,
        public readonly array $items,
        public readonly ?string $receiverName = null,
        public readonly ?string $receiverPhone = null,
        public readonly ?string $receiverAddress = null,
        public readonly ?string $remark = null,
        public readonly ?string $orderNo = null,
        public readonly ?string $province = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $detailAddress = null,
    ) {}

    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'biz_no' => $this->bizNo,
            'order_no' => $this->orderNo,
            'items' => $this->items,
            'receiver_name' => $this->receiverName,
            'receiver_phone' => $this->receiverPhone,
            'receiver_address' => $this->receiverAddress,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'detail_address' => $this->detailAddress,
            'remark' => $this->remark,
        ];
    }

    /**
     * 收货人快照（供 `CainiaoNormalizer::receiverInfo()` 消费）。
     *
     * 结构化字段优先；详细地址缺失时回落已拼好的完整地址，尽量不丢信息。
     *
     * @return array<string, string|null>
     */
    public function buyerInfo(): array
    {
        return [
            'contact_name' => $this->receiverName,
            'contact_phone' => $this->receiverPhone,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'detail_address' => $this->detailAddress ?: $this->receiverAddress,
            'full_address' => $this->receiverAddress,
        ];
    }
}
