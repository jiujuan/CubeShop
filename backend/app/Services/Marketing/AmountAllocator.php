<?php

namespace App\Services\Marketing;

/**
 * 优惠金额分摊引擎（V1.1 F06 / T-034）
 *
 * **纯函数、无 DB 依赖**，可独立单测。规则见 Backend_Design §3.4：
 *  - 按行项目「可用余额」占比分摊，四舍五入到分；
 *  - **尾差记入金额最大的行**（并列最大取索引最小者）；
 *  - 兜底 `rebalance()` 保证不变量：
 *      Σ 分摊 = 优惠总额（误差 0）、每行分摊 ∈ [0, 该行余额]、无负数。
 *
 * 「余额」= 该行金额减去已在前序优惠中分摊掉的部分，用于支持「先满减后券」的
 * 顺序叠加，从而天然规避「两种优惠命中同一行导致行实付为负」的问题。
 */
final class AmountAllocator
{
    /**
     * 把 $discount 按各行余额 $weights 占比分摊到分。
     *
     * @param  float  $discount  优惠总额（>=0；超过 Σweights 时按 Σweights 封顶）
     * @param  array<int, float>  $weights  index => 该行可用余额（>=0）
     * @return array<int, float>  index => 分摊额（键与 $weights 一致；Σ = min(discount, Σweights)）
     */
    public static function allocate(float $discount, array $weights): array
    {
        $weights = array_map(fn ($w) => round((float) $w, 2), $weights);
        $zeros = array_map(fn () => 0.0, $weights);

        $discount = round($discount, 2);
        $base = round(array_sum($weights), 2);

        if ($weights === [] || $discount <= 0.0 || $base <= 0.0) {
            return $zeros;
        }

        // 防御性封顶：分摊总额不得超过可分摊余额
        $discount = min($discount, $base);

        // 1. 按比例四舍五入到分
        $shares = [];
        foreach ($weights as $i => $w) {
            $shares[$i] = round($discount * $w / $base, 2);
        }

        // 2. 尾差（可能正可能负）记入金额最大的行
        $largestIndex = self::largestIndex($weights);
        $allocated = round(array_sum($shares), 2);
        $diff = round($discount - $allocated, 2);
        if ($diff !== 0.0 && $largestIndex !== null) {
            $shares[$largestIndex] = round($shares[$largestIndex] + $diff, 2);
        }

        // 3. 兜底修正越界（浮点/极值场景），保证硬不变量
        return self::rebalance($shares, $weights, $discount);
    }

    /** 余额最大的行索引；并列最大取索引最小者；空集合返回 null */
    private static function largestIndex(array $weights): ?int
    {
        $best = null;
        $bestWeight = -1.0;

        foreach ($weights as $i => $w) {
            $w = round((float) $w, 2);
            if ($w > $bestWeight) {
                $bestWeight = $w;
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * 将越界（负值 / 超余额）的分摊量按「剩余容量从大到小」在行间转移，
     * 使每行落在 [0, 余额] 内且总额保持不变。
     *
     * @param  array<int, float>  $shares
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private static function rebalance(array $shares, array $weights, float $target): array
    {
        $delta = 0.0; // >0 表示有「因封顶溢出的量」需重新分配；<0 表示需从别处扣回

        foreach ($shares as $i => $s) {
            $max = round((float) $weights[$i], 2);
            if ($s < 0.0) {
                $delta = round($delta - $s, 2);
                $shares[$i] = 0.0;
            } elseif ($s > $max) {
                $delta = round($delta + ($s - $max), 2);
                $shares[$i] = $max;
            }
        }

        if ($delta === 0.0) {
            return $shares;
        }

        // 剩余容量（余额 - 已分摊）从大到小进行处理
        $order = array_keys($weights);
        usort($order, function ($a, $b) use ($weights, $shares) {
            $ca = round((float) $weights[$a] - $shares[$a], 2);
            $cb = round((float) $weights[$b] - $shares[$b], 2);

            return $cb <=> $ca;
        });

        foreach ($order as $i) {
            if (round($delta, 2) === 0.0) {
                break;
            }

            $capacity = round((float) $weights[$i] - $shares[$i], 2); // 还能再增加的量
            if ($capacity <= 0.0) {
                continue;
            }

            $move = min(abs($delta), $capacity);
            if ($delta > 0) {
                $shares[$i] = round($shares[$i] + $move, 2);
                $delta = round($delta - $move, 2);
            } else {
                $shares[$i] = round($shares[$i] - $move, 2);
                $delta = round($delta + $move, 2);
            }
        }

        return $shares;
    }
}
