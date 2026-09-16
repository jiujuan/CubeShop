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
 * 属性库首批 10 条为通用属性，后续按品类（服饰鞋包 / 数码电子 / 家居家电 / 美妆个护 /
 * 食品生鲜 / 母婴玩具 / 运动户外 / 图书文具 / 汽车用品）补充，共 50 条。
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
        // sort 越大越靠前；前 10 条为 V1.1 首批通用属性，其后按品类补充
        $attributes = [
            // ==================== 通用（V1.1 首批） ====================
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

            // ==================== 通用补充 ====================
            ['组合规格', 'spec', true, false, false, 50, ['单件装', '两件装', '三件装', '家庭装']],
            ['产地', 'param', true, false, false, 49, ['中国', '日本', '韩国', '德国', '美国', '法国', '意大利']],
            ['保修期', 'param', false, false, false, 48, ['1年', '2年', '3年', '5年', '终身']],

            // ==================== 服饰鞋包 ====================
            ['款式', 'spec', true, false, false, 46, ['圆领', '翻领', '连帽', '立领', '开衫', '套头']],
            ['鞋码', 'spec', true, false, false, 45, ['35', '36', '37', '38', '39', '40', '41', '42', '43', '44']],
            ['面料成分', 'param', true, false, false, 44, ['纯棉', '涤纶', '锦纶', '羊毛', '真丝', '莫代尔', '氨纶']],
            ['洗涤方式', 'param', false, true, false, 43, ['机洗', '手洗', '干洗', '不可水洗']],
            ['鞋跟高度', 'param', false, false, false, 42, ['平底', '低跟（1-3cm）', '中跟（4-6cm）', '高跟（7cm以上）']],

            // ==================== 数码电子 ====================
            ['套餐', 'spec', true, false, false, 40, ['官方标配', '套餐一', '套餐二', '套餐三']],
            ['接口', 'spec', true, false, false, 39, ['USB-A', 'USB-C', 'Lightning', 'Micro-USB', 'HDMI', '3.5mm']],
            ['屏幕尺寸', 'param', true, false, false, 38, ['5.5英寸', '6.1英寸', '6.7英寸', '10.9英寸', '13.3英寸', '15.6英寸', '27英寸']],
            ['运行内存', 'param', true, false, false, 37, ['4GB', '8GB', '12GB', '16GB', '32GB', '64GB']],
            ['处理器', 'param', false, false, false, 36, ['骁龙 8 系', '天玑 9 系', '酷睿 i5', '酷睿 i7', '锐龙 7', 'M3', 'M4']],
            ['操作系统', 'param', true, false, false, 35, ['Android', 'iOS', 'HarmonyOS', 'Windows', 'macOS', 'Linux']],
            ['电池容量', 'param', false, false, false, 34, ['3000mAh', '4500mAh', '5000mAh', '10000mAh', '20000mAh']],
            ['续航时间', 'param', false, false, false, 33, ['8小时', '12小时', '24小时', '72小时', '7天']],
            ['分辨率', 'param', false, false, false, 32, ['720P', '1080P', '2K', '4K', '8K']],
            ['刷新率', 'param', true, false, false, 31, ['60Hz', '90Hz', '120Hz', '144Hz', '165Hz']],
            ['防水等级', 'param', true, false, false, 30, ['IPX4', 'IPX5', 'IPX7', 'IP68']],
            ['连接方式', 'param', false, true, false, 29, ['蓝牙 5.3', 'WiFi 6', 'USB', 'Type-C', 'HDMI', '有线']],
            ['适配机型', 'param', false, true, true, 28, ['iPhone 16', 'iPhone 15', '华为 Mate 60', '小米 14', '三星 Galaxy S24']],

            // ==================== 家居家电 ====================
            ['电压', 'param', true, false, false, 26, ['110V', '220V', '220V-240V']],
            ['适用面积', 'param', false, false, false, 25, ['10-20㎡', '20-40㎡', '40-60㎡', '60㎡以上']],
            ['环保等级', 'param', true, false, false, 24, ['ENF级', 'E0级', 'E1级']],

            // ==================== 美妆个护 ====================
            ['适用肤质', 'param', true, true, false, 22, ['干性肌', '油性肌', '混合肌', '敏感肌', '中性肌', '所有肤质']],
            ['功效', 'param', true, true, false, 21, ['保湿', '美白', '抗皱', '控油', '祛痘', '防晒', '舒缓']],
            ['香调', 'param', true, false, false, 20, ['花香调', '果香调', '木质调', '东方调', '清新调']],
            ['保质期', 'param', true, false, false, 19, ['6个月', '12个月', '24个月', '36个月']],

            // ==================== 食品生鲜 ====================
            ['酒精度', 'param', false, false, false, 17, ['3.5%vol', '5%vol', '8%vol', '12%vol']],
            ['茶叶品类', 'param', true, false, false, 16, ['绿茶', '红茶', '乌龙茶', '普洱茶', '白茶']],

            // ==================== 母婴玩具 ====================
            ['段位', 'spec', true, false, false, 14, ['1段', '2段', '3段', '4段']],
            ['适用年龄', 'param', true, false, false, 13, ['0-6个月', '6-12个月', '1-3岁', '3-6岁', '6岁以上', '成人']],
            ['玩具类型', 'param', true, false, false, 12, ['积木', '毛绒', '拼图', '遥控', '益智', '模型']],

            // ==================== 运动户外 ====================
            ['适用场地', 'param', false, true, false, 10, ['室内', '室外', '健身房', '运动场', '户外徒步', '游泳馆']],
            ['适用运动', 'param', false, true, false, 9, ['跑步', '健身', '篮球', '足球', '瑜伽', '骑行', '登山']],

            // ==================== 图书文具 ====================
            ['装帧', 'spec', false, false, false, 7, ['平装', '精装', '线装', '盒装']],
            ['开本', 'param', false, false, false, 6, ['16开', '32开', '大32开', '8开']],
            ['语言版本', 'spec', false, false, false, 5, ['简体中文', '英文', '中英双语', '其他']],
            ['出版社', 'param', false, false, true, 4, []],

            // ==================== 汽车用品 ====================
            ['适用车型', 'param', false, true, true, 2, ['大众', '丰田', '本田', '日产', '比亚迪', '特斯拉']],
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
