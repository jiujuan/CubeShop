<?php

namespace Database\Seeders;

use App\Services\Shipping\FreightRuleValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * T-053（Stage3）：运费模板种子数据
 *
 * 给运营一组开箱即用的模板（fixed / weight / region 各形态覆盖），
 * 幂等：按 name 查找更新，不重复插入。规则必须通过 FreightRuleValidator
 * （与后台录入同一校验器，fail-closed —— 种子坏了说明校验器/数据有问题）。
 */
class FreightTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                // 全品类兜底：全国统一 8 元
                'name' => '全国统一运费',
                'mode' => 'fixed',
                'rules' => ['amount' => '8.00'],
            ],
            [
                // 首重 1kg 5 元，续重每 1kg 加 2 元
                'name' => '按重量计费（首重1kg）',
                'mode' => 'weight',
                'rules' => [
                    'first_weight_g' => 1000, 'first_fee' => '5.00',
                    'step_weight_g' => 1000, 'step_fee' => '2.00',
                ],
            ],
            [
                // 沪苏浙皖 5 元，其余省份 10 元
                'name' => '江浙沪优惠运费',
                'mode' => 'region',
                'rules' => [
                    'areas' => [
                        ['provinces' => ['310000', '320000', '330000', '340000'], 'amount' => '5.00'],
                    ],
                    'default' => ['amount' => '10.00'],
                ],
            ],
            [
                // 蒙藏甘青宁新 12 元，其余省份 6 元
                'name' => '偏远地区加价运费',
                'mode' => 'region',
                'rules' => [
                    'areas' => [
                        ['provinces' => ['150000', '540000', '620000', '630000', '640000', '650000'], 'amount' => '12.00'],
                    ],
                    'default' => ['amount' => '6.00'],
                ],
            ],
        ];

        foreach ($rows as $row) {
            // 种子规则必须过同一校验器（fail-closed：数据漂移在部署期即暴露）
            $errors = FreightRuleValidator::validate($row['mode'], $row['rules']);
            if ($errors !== []) {
                throw new \RuntimeException(
                    "FreightTemplateSeeder 规则非法（{$row['name']}）：".implode('；', $errors)
                );
            }

            DB::table('freight_templates')->updateOrInsert(
                ['name' => $row['name']],
                [
                    'mode' => $row['mode'],
                    'rules' => json_encode($row['rules'], JSON_UNESCAPED_UNICODE),
                    'status' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
