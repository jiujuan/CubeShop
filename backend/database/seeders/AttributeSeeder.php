<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * E01 属性体系种子（V1.1 T-007）
 *
 * 幂等：按名称/唯一键 updateOrCreate，可重复执行。
 */
class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBrands();
        $this->seedAttributes();
    }

    private function seedBrands(): void
    {
        $brands = [
            ['name' => 'Apple 苹果', 'sort' => 100],
            ['name' => 'HUAWEI 华为', 'sort' => 95],
            ['name' => 'Xiaomi 小米', 'sort' => 90],
            ['name' => 'SAMSUNG 三星', 'sort' => 85],
            ['name' => 'SONY 索尼', 'sort' => 80],
            ['name' => 'Logitech 罗技', 'sort' => 75],
            ['name' => 'PHILIPS 飞利浦', 'sort' => 70],
            ['name' => '京东京造', 'sort' => 65],
        ];

        foreach ($brands as $brand) {
            Brand::updateOrCreate(['name' => $brand['name']], $brand + ['status' => 1]);
        }
    }

    private function seedAttributes(): void
    {
        // [name, type, is_filterable, is_multiple, allow_custom, sort, values[]]
        $attributes = [
            ['颜色', 'spec', true, false, false, 100, ['黑色', '白色', '蓝色', '红色', '灰色', '粉色', '绿色', '金色', '银色']],
            ['尺码', 'spec', true, false, false, 95, ['S', 'M', 'L', 'XL', 'XXL']],
            ['容量', 'spec', true, false, false, 90, ['64GB', '128GB', '256GB', '512GB', '1TB']],
            ['版本', 'spec', false, false, false, 85, ['标准版', '高配版', '尊享版']],
            ['口味', 'spec', false, false, false, 80, ['原味', '香辣', '甜味']],
            ['材质', 'param', true, false, false, 75, ['塑料', '金属', '玻璃', '陶瓷', '皮革', '硅胶', '木质']],
            ['功率', 'param', true, false, false, 70, ['5W', '18W', '30W', '65W', '100W']],
            ['尺寸', 'param', false, false, false, 65, ['小号', '中号', '大号']],
            ['净含量', 'param', false, false, false, 60, ['100g', '250g', '500g', '1kg']],
            ['适用人群', 'param', true, false, false, 55, ['男士', '女士', '儿童', '通用']],
        ];

        foreach ($attributes as [$name, $type, $filterable, $multiple, $custom, $sort, $values]) {
            $attribute = Attribute::updateOrCreate(
                ['name' => $name],
                [
                    'type' => $type,
                    'is_filterable' => $filterable,
                    'is_multiple' => $multiple,
                    'allow_custom' => $custom,
                    'sort' => $sort,
                ],
            );

            foreach ($values as $idx => $value) {
                AttributeValue::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'value' => $value],
                    ['sort' => count($values) - $idx],
                );
            }
        }
    }
}
