<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AttributeSeeder::class,
            ProductSeeder::class,
            OrderConfigSeeder::class,
            ExpressCompanySeeder::class,
            FreightTemplateSeeder::class,
            RefundSeeder::class,
            ReviewNotifySeeder::class,
            AuthSecuritySeeder::class,
            // P-SiteConfig：站点名称 / 大小 logo（运营个性化，firstOrCreate 不覆盖）
            SiteConfigSeeder::class,
            // WMS 对接（P0）：预置默认仓，供配置页/SKU 映射立即使用
            WarehouseSeeder::class,
        ]);
    }
}
