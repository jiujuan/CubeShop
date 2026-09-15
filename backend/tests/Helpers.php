<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductSku;

/**
 * 测试数据工厂辅助（TestPlan v1.0）
 *
 * sqlite :memory: + 外键约束开启，因此按 Category → Product → SKU → Inventory 链路创建。
 */
if (! function_exists('seedRoles')) {
    /** Feature 测试前置：角色与权限码种子（注册分配 customer 角色、后台接口校验权限） */
    function seedRoles(): void
    {
        test()->seed(\Database\Seeders\RolePermissionSeeder::class);
    }
}

if (! function_exists('seedDemoProducts')) {
    /** Feature 测试前置：演示分类与商品种子 */
    function seedDemoProducts(): void
    {
        test()->seed(\Database\Seeders\ProductSeeder::class);
    }
}

if (! function_exists('createTestSku')) {
    function createTestSku(int $stock = 10, string $price = '10.00', int $status = 1, int $productStatus = 1): ProductSku
    {
        $category = Category::create(['parent_id' => 0, 'name' => '测试分类'.uniqid(), 'sort' => 0, 'status' => 1]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => '测试商品'.uniqid(),
            'price' => $price,
            'status' => $productStatus,
        ]);
        $sku = ProductSku::create([
            'product_id' => $product->id,
            'sku_code' => 'SKU-'.uniqid(),
            'specs' => ['规格' => '标准'],
            'price' => $price,
            'status' => $status,
        ]);
        Inventory::create(['sku_id' => $sku->id, 'stock' => $stock, 'locked_stock' => 0]);

        return $sku;
    }
}

if (! function_exists('createTestUser')) {
    function createTestUser(string $username = 'testuser'): \App\Models\SysUser
    {
        return \App\Models\SysUser::create([
            'username' => $username.uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('Test@1234'),
            'nickname' => '测试用户',
            'status' => 1,
        ]);
    }
}
