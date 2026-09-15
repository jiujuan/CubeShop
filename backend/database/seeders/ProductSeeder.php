<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 演示数据：两级分类 + 商品/SKU/库存
 * 数据与原型图对应，便于前后台展示验证
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        if (Category::exists()) {
            return;
        }

        $categories = [
            '服装鞋包' => ['T恤', '运动鞋'],
            '数码配件' => ['耳机', '键盘'],
            '家居生活' => ['保温杯', '收纳'],
            '美妆个护' => ['护肤'],
            '运动户外' => ['户外装备'],
        ];

        $catIds = [];
        foreach ($categories as $root => $children) {
            $rootId = Category::create(['parent_id' => 0, 'name' => $root, 'sort' => count($catIds)])->id;
            $catIds[$root] = $rootId;
            foreach ($children as $i => $child) {
                $catIds["{$root}/{$child}"] = Category::create(['parent_id' => $rootId, 'name' => $child, 'sort' => $i])->id;
            }
        }

        // placeholder SKU 生成，颜色/尺码组合
        $makeSkus = fn (array $colors, array $sizes, float $price, int $stockPerSku) => collect($colors)
            ->flatMap(fn ($color) => collect($sizes)->map(fn ($size) => [
                'specs' => ['颜色' => $color, '尺码' => $size],
                'price' => $price,
                'stock' => $stockPerSku,
            ]))->all();

        $products = [
            [
                'title' => '纯棉短袖T恤', 'subtitle' => '舒适透气，经典百搭',
                'category' => '服装鞋包/T恤', 'price' => 99, 'stock' => 1234, 'sales' => 3456,
                'status' => 1,
                'skus' => $makeSkus(['白色', '黑色'], ['M', 'L'], 99, 300),
            ],
            [
                'title' => '无线蓝牙耳机', 'subtitle' => 'HiFi 音质，长续航',
                'category' => '数码配件/耳机', 'price' => 199, 'stock' => 856, 'sales' => 2341,
                'status' => 1,
                'skus' => [
                    ['specs' => ['颜色' => '黑色'], 'price' => 199, 'stock' => 500],
                    ['specs' => ['颜色' => '白色'], 'price' => 219, 'stock' => 356],
                ],
            ],
            [
                'title' => '不锈钢保温杯', 'subtitle' => '304 不锈钢，便携保温',
                'category' => '家居生活/保温杯', 'price' => 69, 'stock' => 2045, 'sales' => 1876,
                'status' => 1,
                'skus' => [
                    ['specs' => ['容量' => '450ml'], 'price' => 69, 'stock' => 1200],
                    ['specs' => ['容量' => '600ml'], 'price' => 89, 'stock' => 845],
                ],
            ],
            [
                'title' => '机械键盘', 'subtitle' => 'RGB 背光，办公游戏两用',
                'category' => '数码配件/键盘', 'price' => 299, 'stock' => 320, 'sales' => 1102,
                'status' => 1,
                'skus' => [
                    ['specs' => ['轴体' => '青轴'], 'price' => 299, 'stock' => 180],
                    ['specs' => ['轴体' => '红轴'], 'price' => 319, 'stock' => 140],
                ],
            ],
            [
                'title' => '运动跑步鞋', 'subtitle' => '轻盈跑步，缓震舒适',
                'category' => '服装鞋包/运动鞋', 'price' => 259, 'stock' => 668, 'sales' => 2215,
                'status' => 1,
                'skus' => $makeSkus(['白色', '黑色'], ['40', '41', '42'], 259, 110),
            ],
            [
                'title' => '护肤套装', 'subtitle' => '补水保湿，温和修护',
                'category' => '美妆个护/护肤', 'price' => 189, 'stock' => 512, 'sales' => 987,
                'status' => 1,
                'skus' => [
                    ['specs' => ['规格' => '标准装'], 'price' => 189, 'stock' => 512],
                ],
            ],
            [
                'title' => '笔记本电脑支架', 'subtitle' => '可折叠散热，便携稳固',
                'category' => '家居生活/收纳', 'price' => 79, 'stock' => 1156, 'sales' => 1543,
                'status' => 1,
                'skus' => [
                    ['specs' => ['颜色' => '银色'], 'price' => 79, 'stock' => 700],
                    ['specs' => ['颜色' => '深空灰'], 'price' => 79, 'stock' => 456],
                ],
            ],
            [
                'title' => '桌面收纳盒', 'subtitle' => '多格设计，整理办公桌面',
                'category' => '家居生活/收纳', 'price' => 39, 'stock' => 3287, 'sales' => 4102,
                'status' => 1,
                'skus' => [
                    ['specs' => ['规格' => '两格'], 'price' => 39, 'stock' => 2000],
                    ['specs' => ['规格' => '三格'], 'price' => 49, 'stock' => 1287],
                ],
            ],
        ];

        foreach ($products as $item) {
            $categoryName = explode('/', $item['category']);
            $categoryId = count($categoryName) === 2 ? $catIds[$item['category']] : $catIds[$categoryName[0]];

            $product = Product::create([
                'category_id' => $categoryId,
                'title' => $item['title'],
                'subtitle' => $item['subtitle'],
                'main_image' => null,
                'description' => "<p>{$item['title']} —— {$item['subtitle']}。CubeShop 精选好物，品质保障。</p>",
                'price' => $item['price'],
                'status' => $item['status'],
                'sales_count' => $item['sales'],
            ]);

            foreach ($item['skus'] as $i => $sku) {
                $specsText = implode('-', $sku['specs']);
                $skuModel = ProductSku::create([
                    'product_id' => $product->id,
                    'sku_code' => sprintf('CS-%03d-%02d', $product->id, $i + 1),
                    'specs' => $sku['specs'],
                    'price' => $sku['price'],
                    'status' => 1,
                ]);

                Inventory::create(['sku_id' => $skuModel->id, 'stock' => $sku['stock']]);
            }
        }
    }
}
