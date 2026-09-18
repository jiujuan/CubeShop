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
    /** 创建买家（表 users；V1.1 用户表拆分后买家不再使用 SysUser） */
    function createTestUser(string $username = 'testuser'): \App\Models\User
    {
        return \App\Models\User::create([
            'username' => $username.uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('Test@1234'),
            'nickname' => '测试用户',
            'status' => 1,
        ]);
    }
}

if (! function_exists('createTestCategory')) {
    /** 创建分类并返回 id（PG 序列不随事务回滚，禁止硬编码 category_id=1） */
    function createTestCategory(): int
    {
        return \App\Models\Category::create([
            'parent_id' => 0, 'name' => '分类'.uniqid(), 'sort' => 0, 'status' => 1,
        ])->id;
    }
}

/*
 * P2-11 测试辅助：对外 public_id（ULID）↔ 内部 int 主键解析。
 * PublicId::resolve 同时兼容历史 int 主键与 public_id，因此对任意入参包裹均安全。
 */
if (! function_exists('resolvePid')) {
    /** 把对外 public_id（或历史 int）解析为内部 int 主键；解析不出返回 null */
    function resolvePid(string $scope, $value): ?int
    {
        return \App\Support\PublicId::resolve($scope, $value);
    }
}

if (! function_exists('oid')) {
    function oid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_ORDER, $value);
    }
}

if (! function_exists('pid')) {
    function pid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_PRODUCT, $value);
    }
}

if (! function_exists('rid')) {
    function rid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_ORDER_ITEM, $value);
    }
}

if (! function_exists('rfid')) {
    /** 退款单：按 public_id / 历史 int 解析为内部 int 主键 */
    function rfid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_REFUND, $value);
    }
}

if (! function_exists('tid')) {
    /** 客服工单：按 public_id / 历史 int 解析为内部 int 主键 */
    function tid($value): ?int
    {
        return \App\Support\PublicId::resolve(\App\Support\PublicId::SCOPE_TICKET, $value);
    }
}

if (! function_exists('fid')) {
    /** 评价没有 SCOPE 常量，直接按 public_id / 历史 int 解析 */
    function fid($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        return \App\Models\Review::query()->where('public_id', $value)
            ->when(ctype_digit($value) && strlen($value) <= 19, fn ($q) => $q->orWhere('id', (int) $value))
            ->first()?->id;
    }
}
