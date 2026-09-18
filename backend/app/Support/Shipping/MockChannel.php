<?php

namespace App\Support\Shipping;

/**
 * Mock 渠道（V1.1 T-045）：SHIPPING_CHANNEL=mock 时启用，用于本地演示与联调。
 *
 * 确定性生成轨迹（不调用任何第三方）：
 * - 常规运单返回 揽收 → 运输中 → 派送中 三条（相对运单号哈希偏移）；
 * - 单号以「OK」结尾的运单额外返回「已签收」，用于演示签收闭环与 delivered_at 回填。
 */
class MockChannel implements ShippingChannelInterface
{
    public function query(string $companyCode, string $trackingNo): TraceResult
    {
        $seed = crc32($companyCode.$trackingNo);
        $now = now();

        $traces = [
            [
                'context' => '快件已由'.$this->city($seed).'揽收',
                'occurred_at' => $now->copy()->subDays(3)->format('Y-m-d H:i:s'),
                'stage' => TraceStage::PICKUP,
            ],
            [
                'context' => '快件已从'.$this->city($seed).'发出，下一站'.$this->city($seed + 7),
                'occurred_at' => $now->copy()->subDays(2)->format('Y-m-d H:i:s'),
                'stage' => TraceStage::IN_TRANSIT,
            ],
            [
                'context' => '快件正在派送途中，快递员'.$this->courier($seed).'正在为您配送',
                'occurred_at' => $now->copy()->subDay()->format('Y-m-d H:i:s'),
                'stage' => TraceStage::DELIVERING,
            ],
        ];

        if (str_ends_with(strtoupper($trackingNo), 'OK')) {
            $traces[] = [
                'context' => '快件已签收，感谢使用',
                'occurred_at' => $now->copy()->subHours(2)->format('Y-m-d H:i:s'),
                'stage' => TraceStage::DELIVERED,
            ];
        }

        return TraceResult::ok($traces, ['channel' => 'mock', 'company' => $companyCode, 'tracking_no' => $trackingNo]);
    }

    public function available(): bool
    {
        return true;
    }

    private function city(int $seed): string
    {
        $cities = ['深圳', '广州', '杭州', '上海', '北京', '成都', '武汉', '西安'];

        return $cities[$seed % count($cities)];
    }

    private function courier(int $seed): string
    {
        $surnames = ['张', '李', '王', '刘', '陈'];

        return $surnames[$seed % count($surnames)].'师傅';
    }
}
