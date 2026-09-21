<?php

namespace App\Support\Shipping;

/**
 * Mock 渠道（V1.1 T-045）：SHIPPING_CHANNEL=mock 时启用，用于本地演示与联调。
 *
 * 确定性生成轨迹（不调用任何第三方）：
 * - 常规运单返回 揽收 → 运输中 → 派送中 三条（相对运单号哈希偏移）；
 * - 单号以「OK」结尾的运单额外返回「已签收」，用于演示签收闭环与 delivered_at 回填。
 *
 * ⚠️ 轨迹时间必须**确定性**（同一天内多次调用返回完全相同的时间戳）。
 * TracePullService 的轨迹去重键是 `occurred_at|context`，若此处用 now() 生成时间，
 * 每次拉取时间戳都不同 → 去重永不命中 → 每 30 分钟插入一批内容相同的重复轨迹
 * （用户端表现为订单页物流信息「满屏」）。真实渠道返回的是快递公司的固定时间，不存在此问题。
 * 故时间锚点取「当天零点」+ 固定时刻，跨天最多新增一组。
 */
class MockChannel implements ShippingChannelInterface
{
    public function query(string $companyCode, string $trackingNo, ?string $phone = null): TraceResult
    {
        $seed = crc32($companyCode.$trackingNo);
        // 当天零点：同一天内恒定，保证去重生效
        $anchor = now()->startOfDay();

        $traces = [
            [
                'context' => '快件已由'.$this->city($seed).'揽收',
                'occurred_at' => $anchor->copy()->subDays(2)->setTime(9, 12)->format('Y-m-d H:i:s'),
                'stage' => TraceStage::PICKUP,
            ],
            [
                'context' => '快件已从'.$this->city($seed).'发出，下一站'.$this->city($seed + 7),
                'occurred_at' => $anchor->copy()->subDay()->setTime(14, 30)->format('Y-m-d H:i:s'),
                'stage' => TraceStage::IN_TRANSIT,
            ],
            [
                'context' => '快件正在派送途中，快递员'.$this->courier($seed).'正在为您配送',
                'occurred_at' => $anchor->copy()->setTime(10, 5)->format('Y-m-d H:i:s'),
                'stage' => TraceStage::DELIVERING,
            ],
        ];

        if (str_ends_with(strtoupper($trackingNo), 'OK')) {
            $traces[] = [
                'context' => '快件已签收，感谢使用',
                'occurred_at' => $anchor->copy()->setTime(15, 40)->format('Y-m-d H:i:s'),
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
