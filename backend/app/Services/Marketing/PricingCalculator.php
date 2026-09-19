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
        $promotionTier = null;

        foreach (self::STACK_ORDER as $step) {
            if ($step === 'promotion' && $promotion !== null) {
                $scope = (string) ($promotion['scope'] ?? 'all');
                $refs = $promotion['scope_refs'] ?? [];
                $hit = $ctx->scopedIndexes($scope, $refs);
                $runningBase = self::sumByIndexes($remaining, $hit);

                // 梯度按原始命中金额匹配（capture 命中梯度用于快照），再封顶至满减后可用余额
                $promotionTier = self::matchTier($promotion['rules'] ?? [], $ctx->scopeBaseAmount($scope, $refs));
                $promotionDiscount = $promotionTier !== null
                    ? min($promotionTier['discount'], $runningBase)
                    : 0.0;
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
                'freight_share' => self::money(0.0),
                'payable' => self::money(round($l['amount'] - $ps - $cs, 2)),
            ];
        }

        // G3：运费按「行实付」占比分摊到每行（实付合计为 0 时回退按商品金额，仍全 0 则均分），
        // 尾差归最大行。退货退款时据此可得「每行承担运费」，作为运费退不退/退多少的逐行依据。
        $freightAmount = $ctx->freightAmount;
        if ($freightAmount > 0.0 && $detailLines !== []) {
            $weights = [];
            foreach ($detailLines as $l) {
                $payable = (float) $l['payable'];
                $weights[$l['index']] = $payable > 0.0 ? $payable : (float) $l['amount'];
            }
            if (array_sum($weights) <= 0.0) {
                $weights = array_fill_keys(array_keys($weights), 1.0);
            }
            $freightShares = self::spreadFreight($freightAmount, $weights);
            foreach ($detailLines as &$l) {
                $l['freight_share'] = self::money(round($freightShares[$l['index']] ?? 0.0, 2));
            }
            unset($l);
        }

        $goods = $ctx->goodsAmount;
        $freight = $ctx->freightAmount;
        $totalDiscount = round($promotionDiscount + $couponDiscount, 2);

        $details = [
            'v' => self::DETAILS_VERSION,
            'goods_amount' => self::money($goods),
            'freight_amount' => self::money($freight),
            'promotion_discount' => self::money($promotionDiscount),
            'coupon_discount' => self::money($couponDiscount),
            'discount_amount' => self::money($totalDiscount),
        ];
        // 应付由 amount_details 单一公式导出，保证「支付回调重算」与「下单落库」永远同源
        $details['pay_amount'] = self::money(self::recomputePayAmount($details));
        $details += [
            'promotion_id' => $promotion['id'] ?? null,
            'coupon_id' => $coupon['id'] ?? null,
            'user_coupon_id' => $coupon['user_coupon_id'] ?? null,
            // G1：券/满减规则快照——固化名称/面额/类型/门槛/命中梯度，营销规则改后仍能还原当时优惠
            'coupon_snapshot' => self::couponSnapshot($coupon),
            'promotion_snapshot' => self::promotionSnapshot($promotion, $promotionTier),
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
     * 由 `amount_details` 重算应付金额（T-035 支付回调完整性校验）
     *
     * 口径唯一：应付 = 商品总额 − 优惠合计 + 运费。
     * 该方法与 `price()` 共用同一公式，任何时刻二者结果必须一致；
     * 支付回调若发现「订单 pay_amount ≠ 重算值」即判定订单金额被篡改，拒绝入账。
     *
     * @param  array<string, mixed>  $details
     */
    public static function recomputePayAmount(array $details): float
    {
        return round(
            (float) ($details['goods_amount'] ?? 0)
            - (float) ($details['discount_amount'] ?? 0)
            + (float) ($details['freight_amount'] ?? 0),
            2,
        );
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

        // G3：Σ 运费分摊 = 运费（空订单无行可承载运费时跳过）
        $sumFreight = 0.0;
        foreach ($details['lines'] as $l) {
            $sumFreight = round($sumFreight + (float) ($l['freight_share'] ?? 0.0), 2);
        }
        if (! empty($details['lines'])
            && abs($sumFreight - (float) $details['freight_amount']) > self::EPS) {
            throw new \LogicException('分摊不变量破坏：Σ 运费分摊 ≠ 运费');
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
     * 券规则快照（人读，固化当时优惠口径，营销规则被改/删后仍能还原）
     *
     * @param  array<string, mixed>|null  $coupon
     * @return array<string, mixed>|null
     */
    private static function couponSnapshot(?array $coupon): ?array
    {
        if ($coupon === null) {
            return null;
        }

        return [
            'id' => $coupon['id'] ?? null,
            'name' => $coupon['name'] ?? null,
            'type' => $coupon['type'] ?? null,
            'type_label' => $coupon['type_label'] ?? null,
            'amount' => isset($coupon['amount']) ? self::money((float) ($coupon['amount'] ?? 0)) : null,
            'percent' => $coupon['percent'] ?? null,
            'max_discount' => isset($coupon['max_discount']) ? self::money((float) ($coupon['max_discount'] ?? 0)) : null,
            'min_spend' => self::money((float) ($coupon['min_spend'] ?? 0)),
            'scope' => $coupon['scope'] ?? 'all',
            'scope_label' => $coupon['scope_label'] ?? null,
        ];
    }

    /**
     * 满减活动快照（含命中梯度），纠纷时可还原「当时用的什么活动、命中哪一档」
     *
     * @param  array<string, mixed>|null  $promotion
     * @param  array{min?:float, discount?:float}|null  $tier
     * @return array<string, mixed>|null
     */
    private static function promotionSnapshot(?array $promotion, ?array $tier): ?array
    {
        if ($promotion === null) {
            return null;
        }

        return [
            'id' => $promotion['id'] ?? null,
            'name' => $promotion['name'] ?? null,
            'scope' => $promotion['scope'] ?? 'all',
            'scope_label' => $promotion['scope_label'] ?? null,
            'rules' => $promotion['rules'] ?? [],
            'hit_tier' => $tier,
        ];
    }

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

    /**
     * 运费分摊：按权重占比分摊到分，尾差记入权重最大的行（并列取索引最小者）。
     *
     * 不复用 `AmountAllocator::allocate`：后者强制「分摊 ≤ 行余额」，而运费可以
     * 大于单行实付（如 9.9 元商品 + 10 元运费），无余额约束，封顶会导致分摊不闭合。
     *
     * @param  array<int, float>  $weights  index => 权重（>0）
     * @return array<int, float>
     */
    private static function spreadFreight(float $freight, array $weights): array
    {
        $base = round(array_sum($weights), 2);
        if ($weights === [] || $base <= 0.0) {
            return array_fill_keys(array_keys($weights), 0.0);
        }

        $shares = [];
        foreach ($weights as $i => $w) {
            $shares[$i] = round($freight * $w / $base, 2);
        }

        $largest = null;
        $best = -1.0;
        foreach ($weights as $i => $w) {
            if ($w > $best) {
                $best = $w;
                $largest = $i;
            }
        }

        $diff = round($freight - round(array_sum($shares), 2), 2);
        if ($diff !== 0.0 && $largest !== null) {
            $shares[$largest] = round($shares[$largest] + $diff, 2);
        }

        return $shares;
    }

    /** 金额统一序列化为 2 位小数字符串 */
    private static function money(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }
}
