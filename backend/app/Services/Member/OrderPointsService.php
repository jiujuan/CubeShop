<?php

namespace App\Services\Member;

use App\Models\Order;
use App\Models\UserPointLog;
use App\Support\Member\PointsRules;

/**
 * 消费返积分（会员成长计划 S3）
 *
 * 设计文档：docs/design/points-checkin-membership.md §16 S3
 *
 * 触发：订单状态机进入 paid（OrderService::transitionTo 唯一执行点）后调用本服务。
 * 这样无论支付回调、后台标记支付、还是退款驳回回退到 paid，发分逻辑只有一处，
 * 且靠 {@see PointsService::credit()} 的 biz_key 幂等键（order:{id}:earn）兜底，
 * 重复回调 / 退款驳回二次进入 paid 都不会重复加分。
 *
 * 基数：订单实付 `pay_amount`（元）。
 *  - earn_on_freight = 0（默认）：扣掉运费（D5：运费不计分）；
 *  - earn_on_freight = 1：含运费。
 * 公式：floor(基数 / earn_rate)；earn_rate = 0 或基数 ≤ 0 或功能未开启 → 不返。
 *
 * 依赖单向 Services → Support（PointsRules 提供 biz_key 拼装）。
 */
final class OrderPointsService
{
    public function __construct(
        private readonly PointsService $points,
        private readonly PointsSettings $settings,
    ) {
    }

    /**
     * 订单支付成功发分
     *
     * @return UserPointLog|null 实际发出的流水；未开启 / 0 分 / 异常时返回 null
     */
    public function award(Order $order): ?UserPointLog
    {
        if (! $this->settings->enabled()) {
            return null;
        }

        $rate = $this->settings->earnRate();
        if ($rate <= 0) {
            return null;
        }

        $base = $this->earnBase($order);
        if (bccomp($base, '0', 2) <= 0) {
            return null;
        }

        $earned = (int) bcdiv($base, (string) $rate, 0);
        if ($earned <= 0) {
            return null;
        }

        $bizKey = PointsRules::bizKey('order', $order->id, 'earn');

        return $this->points->credit(
            $order->user_id,
            $earned,
            PointsRules::TYPE_EARN,
            'order',
            $order->id,
            $bizKey,
            sprintf('订单 %s 消费返积分（实付 ¥%s）', $order->order_no, $base),
        );
    }

    /**
     * 返分基数（元）：默认 = 实付；运费不计分时扣掉运费
     */
    private function earnBase(Order $order): string
    {
        $pay = (string) ($order->pay_amount ?? 0);

        if ($this->settings->earnOnFreight()) {
            return $pay;
        }

        return bcsub($pay, (string) ($order->freight_amount ?? 0), 2);
    }
}
