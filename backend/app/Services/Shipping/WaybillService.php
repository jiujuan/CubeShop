<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Support\Shipping\WaybillChannelInterface;
use App\Support\Shipping\WaybillRequest;
use App\Support\Shipping\WaybillResult;

/**
 * 电子面单申请服务（出单侧，与轨迹侧 TracePullService 同形）
 *
 * 组装出单请求（收件人取自订单地址快照、寄件人取自配置），委托渠道适配器出单。
 * 渠道不可用（Null）时由调用方（OrderService）决定回落手动录入。
 */
class WaybillService
{
    public function __construct(private readonly WaybillChannelInterface $channel)
    {
    }

    /**
     * 为某订单的某快递公司申请电子面单
     *
     * @param  Order  $order  订单（取 address_snapshot 里的收件人）
     * @param  string  $companyCode  平台快递公司编码（SF/ZTO…），渠道自行转换
     */
    public function issueForOrder(Order $order, string $companyCode): WaybillResult
    {
        $snapshot = is_array($order->address_snapshot) ? $order->address_snapshot : [];
        $full = trim((string) ($snapshot['full_address'] ?? ''));
        if ($full === '') {
            $full = implode('', array_filter([
                $snapshot['province'] ?? '',
                $snapshot['city'] ?? '',
                $snapshot['district'] ?? '',
                $snapshot['detail_address'] ?? '',
            ]));
        }

        $request = new WaybillRequest(
            orderNo: (string) $order->order_no,
            companyCode: $companyCode,
            recipientName: trim((string) ($snapshot['contact_name'] ?? '')),
            recipientPhone: trim((string) ($snapshot['contact_phone'] ?? '')),
            recipientAddress: $full,
            senderName: trim((string) config('services.waybill.sender_name')),
            senderPhone: trim((string) config('services.waybill.sender_phone')),
            senderAddress: trim((string) config('services.waybill.sender_address')),
            weightGram: (int) config('services.waybill.default_weight_gram', 1000),
        );

        return $this->channel->issue($request);
    }
}
