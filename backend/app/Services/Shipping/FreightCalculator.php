<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Dto\FreightResult;

/**
 * 运费计算引擎（纯函数，无 DB / Config 依赖，入参即真相 → 单测可穷举）
 *
 * 口径（2026-09-18 已确认）：
 *  - A. 多模板混合订单：按模板分组各自计费，组间取最大值（避免重复计费）；
 *  - B. region 模式按省行政区划代码（GB/T 2260 六位）匹配，code↔name 由 RegionService 映射；
 *  - C. region 未命中任何 area 且无 default → not_support=true，调用方拒单（409）；
 *  - D. weight 模式下重量为 0 → 只收首重费（0 续重）。
 *
 * 模板规则结构（rules JSON，见 FreightRuleValidator）：
 *  - fixed  : { amount }
 *  - weight : { first_weight_g, first_fee, step_weight_g, step_fee }
 *  - region : { default?: FeeSpec, areas: [{ provinces: string[], ...FeeSpec }] }
 *             FeeSpec = { amount } 或 { first_weight_g, first_fee, step_weight_g, step_fee }
 *
 * 金额一律 bcmath 字符串运算，两位小数；调用方（OrderService / freight-preview）负责
 * 从 DB/Config 组装入参。模板缺失时降级为 defaultRules（与既有 fixed 行为一致）。
 */
final class FreightCalculator
{
    /**
     * @param  array<int, array{template_id: int|null, weight_g: int, quantity: int, price: string}>  $lines
     * @param  array<int|string, array{mode: string, rules: array}>  $templates  template_id => 模板
     * @param  array{mode: string, rules: array}  $defaultRules  未绑定模板商品走的全局默认规则
     */
    public static function calculate(
        array $lines,
        array $templates,
        array $defaultRules,
        string $freeShippingThreshold,
        ?string $provinceCode = null,
    ): FreightResult {
        $groups = [];
        foreach ($lines as $line) {
            $key = $line['template_id'] === null ? 'default' : (string) $line['template_id'];
            $groups[$key][] = $line;
        }

        $detail = [];
        $notSupport = false;
        $maxAmount = '0.00';
        $hasGroups = false;

        foreach ($groups as $key => $groupLines) {
            $spec = $key === 'default'
                ? $defaultRules
                : ($templates[$key] ?? $defaultRules); // 模板缺失 → 降级默认（调用方另记 warning）

            $groupWeight = 0;
            foreach ($groupLines as $line) {
                $groupWeight += (int) $line['weight_g'] * (int) $line['quantity'];
            }

            $computed = self::computeSpec($spec, $groupLines, $groupWeight, $provinceCode);
            $notSupport = $notSupport || $computed['not_support'];

            $detail[] = [
                'template_id' => $key === 'default' ? null : (int) $key,
                'mode' => $spec['mode'],
                'weight_g' => $groupWeight,
                'amount' => $computed['amount'],
                'source' => $computed['source'],
                // G2：解析后的计费规格（固定额 / 首续重），固化「运费怎么算出来的」
                'spec' => $computed['spec'] ?? null,
            ];

            if (! $computed['not_support']) {
                $hasGroups = true;
                if (bccomp($computed['amount'], $maxAmount, 2) === 1) {
                    $maxAmount = $computed['amount'];
                }
            }
        }

        // 任一分组不可配送 → 整单拒（决策 C）
        if ($notSupport) {
            return new FreightResult('0.00', false, null, true, $detail);
        }

        $goodsAmount = '0.00';
        foreach ($lines as $line) {
            $goodsAmount = bcadd($goodsAmount, bcmul((string) $line['price'], (string) $line['quantity'], 2), 2);
        }

        $freight = $hasGroups ? $maxAmount : '0.00';
        $threshold = (string) $freeShippingThreshold;

        // 包邮门槛（全局配置）：≥ threshold 运费归 0
        if (bccomp($threshold, '0.00', 2) === 1 && bccomp($goodsAmount, $threshold, 2) !== -1) {
            return new FreightResult('0.00', true, null, false, $detail);
        }

        $gap = bccomp($threshold, '0.00', 2) === 1 ? bcsub($threshold, $goodsAmount, 2) : null;

        return new FreightResult($freight, false, $gap, false, $detail);
    }

    /**
     * 单分组计费。返回 ['amount'=>string, 'source'=>string, 'not_support'=>bool]
     * source：fixed / weight / region_area / region_default / region_unmatched
     *
     * @param  array<int, array{template_id: int|null, weight_g: int, quantity: int, price: string}>  $groupLines
     * @return array{amount: string, source: string, not_support: bool}
     */
    private static function computeSpec(array $spec, array $groupLines, int $groupWeight, ?string $provinceCode): array
    {
        $rules = $spec['rules'] ?? [];

        return match ($spec['mode']) {
            'fixed' => [
                'amount' => self::money($rules['amount'] ?? '0.00'),
                'source' => 'fixed',
                'not_support' => false,
                'spec' => ['amount' => self::money($rules['amount'] ?? '0.00')],
            ],
            'weight' => [
                'amount' => self::weightFee($rules, $groupWeight),
                'source' => 'weight',
                'not_support' => false,
                'spec' => self::weightSpec($rules),
            ],
            'region' => self::computeRegion($rules, $groupLines, $groupWeight, $provinceCode),
            default => ['amount' => '0.00', 'source' => 'unknown_mode', 'not_support' => false, 'spec' => null],
        };
    }

    /**
     * region 计费：按省 code 命中 areas，未命中走 default，两者皆无 → not_support。
     *
     * @param  array<int, array{template_id: int|null, weight_g: int, quantity: int, price: string}>  $groupLines
     * @return array{amount: string, source: string, not_support: bool}
     */
    private static function computeRegion(array $rules, array $groupLines, int $groupWeight, ?string $provinceCode): array
    {
        $areas = $rules['areas'] ?? [];
        $default = $rules['default'] ?? null;

        $matched = null;
        if ($provinceCode !== null) {
            foreach ($areas as $area) {
                if (in_array($provinceCode, array_map('strval', $area['provinces'] ?? []), true)) {
                    $matched = $area;
                    break;
                }
            }
        }

        if ($matched !== null) {
            return [
                'amount' => self::feeSpecAmount($matched, $groupWeight),
                'source' => 'region_area',
                'not_support' => false,
                'spec' => self::feeSpecSpec($matched),
            ];
        }

        if ($default !== null) {
            return [
                'amount' => self::feeSpecAmount($default, $groupWeight),
                'source' => 'region_default',
                'not_support' => false,
                'spec' => self::feeSpecSpec($default),
            ];
        }

        return ['amount' => '0.00', 'source' => 'region_unmatched', 'not_support' => true, 'spec' => null];
    }

    /**
     * FeeSpec 计费：有 amount 按固定金额；否则按重量口径（0 重 → 只收首费，决策 D）。
     *
     * @param  array<string, mixed>  $spec
     */
    private static function feeSpecAmount(array $spec, int $groupWeight): string
    {
        if (array_key_exists('amount', $spec)) {
            return self::money($spec['amount']);
        }

        return self::weightFee($spec, $groupWeight);
    }

    /** 重量计费：first_fee + ceil(超出部分 / step) × step_fee */
    private static function weightFee(array $rules, int $totalWeightG): string
    {
        $firstWeight = max(1, (int) ($rules['first_weight_g'] ?? 1000));
        $firstFee = self::money($rules['first_fee'] ?? '0.00');
        $stepWeight = max(1, (int) ($rules['step_weight_g'] ?? 1000));
        $stepFee = self::money($rules['step_fee'] ?? '0.00');

        $over = max(0, $totalWeightG - $firstWeight);
        if ($over === 0) {
            return $firstFee;
        }

        $steps = (int) ceil($over / $stepWeight);

        return bcadd($firstFee, bcmul($stepFee, (string) $steps, 2), 2);
    }

    /** 重量计费规格（首重/首费/续重/续费），G2 运费明细快照用 */
    private static function weightSpec(array $rules): array
    {
        return [
            'first_weight_g' => (int) ($rules['first_weight_g'] ?? 1000),
            'first_fee' => self::money($rules['first_fee'] ?? '0.00'),
            'step_weight_g' => (int) ($rules['step_weight_g'] ?? 1000),
            'step_fee' => self::money($rules['step_fee'] ?? '0.00'),
        ];
    }

    /** FeeSpec → 计费规格快照（固定额或首续重） */
    private static function feeSpecSpec(array $spec): array
    {
        if (array_key_exists('amount', $spec)) {
            return ['amount' => self::money($spec['amount'])];
        }

        return self::weightSpec($spec);
    }

    /** 归一为两位小数字符串（容错标量输入） */
    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
