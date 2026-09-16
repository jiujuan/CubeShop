<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Dto\OrderContext;

/**
 * 订单优惠金额计算器（V1.1 F06 / T-034，关键路径）
 *
 * **纯函数、无 DB 依赖**：输入 `OrderContext` + 券参数数组 + 满减参数数组，
 * 输出 `orders.amount_details` 结构。所有分摊规则集中在此，便于单测与复用
 * （退款分摊 T-036、三期积分抵扣 T-057 复用同套框架）。
 *
 * ── 规则（Backend_Design §3.4，评审口径）────────────────────────────
 * 1. **叠加顺序**：满减在前、券在后（`STACK_ORDER`，即「先满减后券」）。
 * 2. **满减**：按活动命中范围（all/category/product）的**原始金额**匹配**最优梯度**
 *    （同活动内取优惠最大的一档），再封顶至命中行可用余额。
 * 3. **券**：
 *      - 门槛 `min_spend` 按**原始**命中范围金额判定（原价够格即可）；
 *      - 优惠额：fixed 直减 amount；percent 按比例 `base*(100-percent)/100` 并受
 *        `max_discount` 封顶（`base` 为原始命中金额）；
 *      - 最终封顶至「满减后」命中行可用余额，避免与满减叠加后行实付为负。
 * 4. **分摊**：按行可用余额占比分摊，尾差记入金额最大的行（见 AmountAllocator）。
 * 5. **不变量**（`assertInvariants` 自检）：
 *      Σ 行分摊 = 对应优惠总额；任一行实付 ≥ 0；
 *      应付 = 商品总额 − 券 − 满减 + 运费 ≥ 0。
 *
 * ── 券参数数组结构（`$coupon`）────────────────────────────────────
 *   ['id'=>int, 'user_coupon_id'=>int, 'type'=>'fixed|percent', 'amount'=>float|null,
 *    'percent'=>int|null, 'max_discount'=>float|null, 'min_spend'=>float,
 *    'scope'=>'all|category|product', 'scope_refs'=>array]
 *
 * ── 满减参数数组结构（`$promotion`）─────────────────────────────────
 *   ['id'=>int, 'rules'=>[['min'=>float,'discount'=>float], ...],
 *    'scope'=>'all|category|product', 'scope_refs'=>array]
 */
final class PricingCalculator
{
    /** amount_details 结构版本号（口径演进时递增，历史订单据此兼容展示） */
    public const DETAILS_VERSION = 1;

    /** 优惠叠加顺序：先满减后券（评审口径） */
    public const STACK_ORDER = ['promotion', 'coupon'];

    /** 金额比较容差（分） */
    private const EPS = 0.000001;

    /**
     * 计算订单金额明细快照
     *
     * @param  array<string, mixed>|null  $coupon
     * @param  array<string, mixed>|null  $promotion
     * @return array<string, mixed> amount_details
     */
    public static function price(OrderContext $ctx, ?array $coupon = null, ?array $promotion = null): array
    {
        $indexes = array_keys($ctx->lines);
        $remaining = [];
        foreach ($ctx->lines as $l) {
            $remaining[$l['index']] = $l['amount'];
        }

        $promotionShares = array_fill_keys($indexes, 0.0);
        $couponShares = array_fill_keys($indexes, 0.0);
        $promotionDiscount = 0.0;
        $couponDiscount = 0.0;

        foreach (self::STACK_ORDER as $step) {
            if ($step === 'promotion' && $promotion !== null) {
                $scope = (string) ($promotion['scope'] ?? 'all');
                $refs = $promotion['scope_refs'] ?? [];
                $hit = $ctx->scopedIndexes($scope, $refs);
                $runningBase = self::sumByIndexes($remaining, $hit);

                // 梯度按原始命中金额匹配，再封顶至满减后可用余额
                $promotionDiscount = min(
                    self::promotionDiscount($promotion['rules'] ?? [], $ctx->scopeBaseAmount($scope, $refs)),
                    $runningBase,
                );
                $promotionDiscount = round(max(0.0, $promotionDiscount), 2);

                $promotionShares = self::spread($promotionDiscount, $remaining, $hit);
                foreach ($promotionShares as $i => $s) {
                    $remaining[$i] = round($remaining[$i] - $s, 2);
                }
            }

            if ($step === 'coupon' && $coupon !== null) {
                $scope = (string) ($coupon['scope'] ?? 'all');
                $refs = $coupon['scope_refs'] ?? [];
                $hit = $ctx->scopedIndexes($scope, $refs);
                $runningBase = self::sumByIndexes($remaining, $hit);

                // 券折扣按原始命中金额计算，最终封顶至满减后可用余额
                $couponDiscount = min(
                    self::couponDiscount($coupon, $ctx->scopeBaseAmount($scope, $refs)),
                    $runningBase,
                );
                $couponDiscount = round(max(0.0, $couponDiscount), 2);

                $couponShares = self::spread($couponDiscount, $remaining, $hit);
                foreach ($couponShares as $i => $s) {
                    $remaining[$i] = round($remaining[$i] - $s, 2);
                }
            }
        }

        // 组装行明细
        $detailLines = [];
        foreach ($ctx->lines as $l) {
            $i = $l['index'];
            $cs = round($couponShares[$i] ?? 0.0, 2);
            $ps = round($promotionShares[$i] ?? 0.0, 2);

            $detailLines[] = [
                'index' => $i,
                'product_id' => $l['product_id'],
                'sku_id' => $l['sku_id'],
                'amount' => self::money($l['amount']),
                'promotion_share' => self::money($ps),
                'coupon_share' => self::money($cs),
                'payable' => self::money(round($l['amount'] - $ps - $cs, 2)),
            ];
        }

        $goods = $ctx->goodsAmount;
        $freight = $ctx->freightAmount;
        $totalDiscount = round($promotionDiscount + $couponDiscount, 2);
        $payAmount = round($goods - $totalDiscount + $freight, 2);

        $details = [
            'v' => self::DETAILS_VERSION,
            'goods_amount' => self::money($goods),
            'freight_amount' => self::money($freight),
            'promotion_discount' => self::money($promotionDiscount),
            'coupon_discount' => self::money($couponDiscount),
            'discount_amount' => self::money($totalDiscount),
            'pay_amount' => self::money($payAmount),
            'promotion_id' => $promotion['id'] ?? null,
            'coupon_id' => $coupon['id'] ?? null,
            'user_coupon_id' => $coupon['user_coupon_id'] ?? null,
            'lines' => $detailLines,
        ];

        // 自检硬不变量：任何组合下都不允许出现负数或分摊不闭合
        self::assertInvariants($details);

        return $details;
    }

    /**
     * 券优惠额（fixed 直减；percent 按比例并受 max_discount 封顶）
     *
     * @param  array<string, mixed>  $coupon
     */
    public static function couponDiscount(array $coupon, float $base): float
    {
        if ($base <= 0.0) {
            return 0.0;
        }

        if (($coupon['type'] ?? null) === 'fixed') {
            return round(min((float) ($coupon['amount'] ?? 0), $base), 2);
        }

        // percent：percent 为「用户实付百分比」，如 80 表示 8 折
        $percent = (int) ($coupon['percent'] ?? 100);
        $discount = round($base * (100 - $percent) / 100, 2);

        if (($coupon['max_discount'] ?? null) !== null) {
            $discount = min($discount, (float) $coupon['max_discount']);
        }

        return round(min($discount, $base), 2);
    }

    /**
     * 满减最优梯度匹配
     *
     * @param  array<int, array{min?:float|int|string, discount?:float|int|string}>  $rules
     * @return array{min:float, discount:float}|null  命中的梯度（无命中返回 null）
     */
    public static function matchTier(array $rules, float $base): ?array
    {
        $best = null;

        foreach ($rules as $rule) {
            $min = (float) ($rule['min'] ?? 0);
            $discount = (float) ($rule['discount'] ?? 0);

            if ($base + self::EPS >= $min && ($best === null || $discount > $best['discount'])) {
                $best = ['min' => $min, 'discount' => $discount];
            }
        }

        return $best;
    }

    /**
     * 满减优惠额（最优梯度，且不超过命中金额）
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    public static function promotionDiscount(array $rules, float $base): float
    {
        if ($base <= 0.0) {
            return 0.0;
        }

        $tier = self::matchTier($rules, $base);
        if ($tier === null) {
            return 0.0;
        }

        return round(min($tier['discount'], $base), 2);
    }

    /**
     * 分摊硬不变量断言（供 price() 自检与外部校验）
     *
     * @param  array<string, mixed>  $details
     *
     * @throws \LogicException 任一条不变量被破坏
     */
    public static function assertInvariants(array $details): void
    {
        $sumCoupon = 0.0;
        $sumPromotion = 0.0;
        $sumPayable = 0.0;
        $sumAmount = 0.0;

        foreach ($details['lines'] as $l) {
            $cs = (float) $l['coupon_share'];
            $ps = (float) $l['promotion_share'];
            $payable = (float) $l['payable'];
            $amount = (float) $l['amount'];

            if ($cs < -self::EPS || $ps < -self::EPS) {
                throw new \LogicException('分摊不变量破坏：存在负数分摊');
            }
            if ($payable < -self::EPS) {
                throw new \LogicException('分摊不变量破坏：某行实付为负');
            }

            $sumCoupon = round($sumCoupon + $cs, 2);
            $sumPromotion = round($sumPromotion + $ps, 2);
            $sumPayable = round($sumPayable + $payable, 2);
            $sumAmount = round($sumAmount + $amount, 2);
        }

        if (abs($sumCoupon - (float) $details['coupon_discount']) > self::EPS) {
            throw new \LogicException('分摊不变量破坏：Σ 券分摊 ≠ 券优惠总额');
        }
        if (abs($sumPromotion - (float) $details['promotion_discount']) > self::EPS) {
            throw new \LogicException('分摊不变量破坏：Σ 满减分摊 ≠ 满减优惠总额');
        }
        if (abs($sumAmount - (float) $details['goods_amount']) > self::EPS) {
            throw new \LogicException('分摊不变量破坏：Σ 行金额 ≠ 商品总额');
        }
        if (abs($sumPayable - round($sumAmount - $sumCoupon - $sumPromotion, 2)) > self::EPS) {
            throw new \LogicException('分摊不变量破坏：Σ 行实付 ≠ 商品总额 − 优惠');
        }

        $expectedPay = round((float) $details['goods_amount'] - (float) $details['discount_amount'] + (float) $details['freight_amount'], 2);
        if (abs((float) $details['pay_amount'] - $expectedPay) > self::EPS) {
            throw new \LogicException('分摊不变量破坏：应付 ≠ 商品总额 − 优惠 + 运费');
        }
        if ((float) $details['pay_amount'] < -self::EPS) {
            throw new \LogicException('分摊不变量破坏：应付为负');
        }
    }

    // ---------------- 内部 ----------------

    /**
     * 在命中行上按余额占比分摊，返回 index => 分摊额
     *
     * @param  array<int, float>  $remaining
     * @param  array<int, int>  $hitIndexes
     * @return array<int, float>
     */
    private static function spread(float $discount, array $remaining, array $hitIndexes): array
    {
        $weights = [];
        foreach ($hitIndexes as $i) {
            $weights[$i] = $remaining[$i] ?? 0.0;
        }

        return AmountAllocator::allocate($discount, $weights);
    }

    /** @param array<int, int> $indexes */
    private static function sumByIndexes(array $remaining, array $indexes): float
    {
        $sum = 0.0;
        foreach ($indexes as $i) {
            $sum = round($sum + ($remaining[$i] ?? 0.0), 2);
        }

        return round($sum, 2);
    }

    /** 金额统一序列化为 2 位小数字符串 */
    private static function money(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }
}
