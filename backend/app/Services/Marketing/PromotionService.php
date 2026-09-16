<?php

namespace App\Services\Marketing;

use App\Exceptions\BusinessException;
use App\Models\Promotion;
use App\Services\Marketing\Dto\OrderContext;

/**
 * 满减活动服务（V1.1 二期 F06 / T-035 匹配，T-037 精化展示）
 *
 * 规则（Backend_Design §3.4）：
 *  - 只在 `status=active` 且当前时间落在 `[start_at, end_at]` 内的活动里挑选；
 *  - 同一活动内按命中金额取**最优梯度**（见 `PricingCalculator::matchTier`）；
 *  - **多活动并存时取优惠最大的一个，不叠加**（评审口径）；
 *  - 活动范围（all/category/product）按行项目原始金额命中，未命中即优惠为 0。
 *
 * 下单时不传 `promotion_id` 则调用 `match()` 自动匹配；显式传入则用 `resolveUsable()` 校验后使用。
 */
class PromotionService
{
    /**
     * 自动匹配订单命中的最优满减活动
     *
     * @return Promotion|null 无命中返回 null
     */
    public function match(OrderContext $ctx): ?Promotion
    {
        $promotions = $this->runningPromotions();

        $best = null;
        $bestDiscount = 0.0;

        foreach ($promotions as $promotion) {
            $discount = PricingCalculator::promotionDiscount(
                $promotion->rules ?? [],
                $ctx->scopeBaseAmount($promotion->scope, $promotion->scope_refs ?? []),
            );

            // 严格大于：并列时保留先出现的（id 升序），保证结果稳定可测
            if ($discount > $bestDiscount) {
                $bestDiscount = $discount;
                $best = $promotion;
            }
        }

        return $best;
    }

    /**
     * 校验指定活动可用于该订单上下文（启用 + 时间窗口 + 命中范围）
     *
     * @throws BusinessException 不存在 / 未运行 / 范围不命中
     */
    public function resolveUsable(int $promotionId, OrderContext $ctx): Promotion
    {
        $promotion = Promotion::find($promotionId);
        if (! $promotion) {
            throw BusinessException::notFound('满减活动不存在');
        }
        if (! $promotion->isRunning()) {
            throw BusinessException::conflict('满减活动未开始或已结束');
        }
        if ($promotion->scope !== Promotion::SCOPE_ALL
            && $ctx->scopeBaseAmount($promotion->scope, $promotion->scope_refs ?? []) <= 0) {
            throw BusinessException::conflict('订单中没有参与该满减活动的商品');
        }

        return $promotion;
    }

    /** 当前运行中的活动（启用且在时间窗口内），按 id 升序保证匹配稳定 */
    private function runningPromotions(): \Illuminate\Support\Collection
    {
        $now = now();

        return Promotion::query()
            ->where('status', Promotion::STATUS_ACTIVE)
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->orderBy('id')
            ->get();
    }
}
