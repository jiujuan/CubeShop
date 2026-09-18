<?php

namespace App\Services\Shipping;

use App\Services\Common\RegionService;

/**
 * 运费模板规则校验器（store/update 与下单前共用，fail-closed）
 *
 * 返回错误串列表（空数组 = 通过）；控制器侧转 422。region 的 provinces
 * 必须是 RegionService 认可的合法省 code（决策 B：按行政区划代码匹配）。
 */
final class FreightRuleValidator
{
    public const MODES = ['fixed', 'weight', 'region'];

    /** @return string[] 错误列表 */
    public static function validate(string $mode, mixed $rules): array
    {
        if (! in_array($mode, self::MODES, true)) {
            return ["计费方式 mode 非法（仅支持 fixed / weight / region）"];
        }
        if (! is_array($rules)) {
            return ['规则 rules 必须是对象'];
        }

        return match ($mode) {
            'fixed' => self::validateFixed($rules),
            'weight' => self::validateWeight($rules),
            'region' => self::validateRegion($rules),
            default => ['计费方式 mode 非法'],
        };
    }

    /** @return string[] */
    private static function validateFixed(array $rules): array
    {
        return self::checkAmount($rules['amount'] ?? null, 'amount');
    }

    /** @return string[] */
    private static function validateWeight(array $rules): array
    {
        $errors = [];
        foreach (['first_weight_g' => '首重', 'step_weight_g' => '续重单位'] as $field => $label) {
            $v = $rules[$field] ?? null;
            if (! is_int($v) && ! (is_string($v) && ctype_digit($v)) || (int) $v <= 0) {
                $errors[] = "{$label} {$field} 必须是正整数（克）";
            }
        }
        $errors = array_merge(
            $errors,
            self::checkAmount($rules['first_fee'] ?? null, 'first_fee'),
            self::checkAmount($rules['step_fee'] ?? null, 'step_fee'),
        );

        return $errors;
    }

    /** @return string[] */
    private static function validateRegion(array $rules): array
    {
        $errors = [];
        $areas = $rules['areas'] ?? null;

        if (! is_array($areas) || $areas === []) {
            return ['region 规则必须包含非空的 areas 数组'];
        }

        foreach ($areas as $i => $area) {
            if (! is_array($area)) {
                $errors[] = "areas[{$i}] 必须是对象";
                continue;
            }

            $provinces = $area['provinces'] ?? null;
            if (! is_array($provinces) || $provinces === []) {
                $errors[] = "areas[{$i}].provinces 必须是非空省 code 数组";
            } else {
                foreach ($provinces as $code) {
                    if (! is_string($code) || ! RegionService::isValidProvinceCode($code)) {
                        $errors[] = "areas[{$i}].provinces 含非法省行政区划代码：".(string) $code;
                    }
                }
            }

            foreach (self::feeSpecErrors($area, "areas[{$i}]") as $err) {
                $errors[] = $err;
            }
        }

        if (array_key_exists('default', $rules) && $rules['default'] !== null) {
            if (! is_array($rules['default'])) {
                $errors[] = 'default 必须是对象或 null';
            } else {
                foreach (self::feeSpecErrors($rules['default'], 'default') as $err) {
                    $errors[] = $err;
                }
            }
        }

        return $errors;
    }

    /**
     * FeeSpec（amount 或完整 weight 四元组）校验
     *
     * @param  array<string, mixed>  $spec
     * @return string[]
     */
    private static function feeSpecErrors(array $spec, string $prefix): array
    {
        $hasAmount = array_key_exists('amount', $spec);
        $hasWeight = isset($spec['first_weight_g'], $spec['first_fee']);

        if ($hasAmount) {
            return self::checkAmount($spec['amount'], "{$prefix}.amount");
        }

        if ($hasWeight) {
            return self::validateWeight($spec);
        }

        return ["{$prefix} 必须包含 amount 或完整的首重/续重字段"];
    }

    /**
     * @return string[]
     */
    private static function checkAmount(mixed $value, string $field): array
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return ["{$field} 必须是非负数字"];
        }

        return [];
    }
}
