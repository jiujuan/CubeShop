<?php

namespace App\Support\Shipping;

/**
 * 电子面单申请请求（出单侧 DTO，与轨迹查询 ShippingChannelInterface 同源设计）
 *
 * 仅承载「发货需要告诉快递公司的最小信息」：收寄件人、重量、品名、内部单号（用于回传关联）。
 * 渠道自行把 platform code 转成自己的编码（见 {@see \App\Support\CarrierCode}）。
 */
final class WaybillRequest
{
    public function __construct(
        public readonly string $orderNo,
        public readonly string $companyCode,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $recipientAddress,
        public readonly string $senderName = '',
        public readonly string $senderPhone = '',
        public readonly string $senderAddress = '',
        public readonly int $weightGram = 0,
        public readonly string $cargoName = '商品',
    ) {
    }
}
