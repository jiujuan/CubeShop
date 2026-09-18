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
            RefundSeeder::class,
            ReviewNotifySeeder::class,
            AuthSecuritySeeder::class,
        ]);
    }
}
