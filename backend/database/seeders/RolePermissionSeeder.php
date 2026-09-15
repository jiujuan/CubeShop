<?php

namespace Database\Seeders;

use App\Models\SysUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * P1 种子数据：权限码（对齐 API 文档 9）、角色、初始账号
 *
 * 初始账号：admin / Admin@123（超级管理员），operator / Operator@123（运营）
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * API 文档 9 的权限码参考表
     */
    public const PERMISSIONS = [
        'product.view',
        'product.create',
        'product.update',
        'category.manage',
        'order.view',
        'order.ship',
        'order.export',
        'refund.view',
        'refund.process',
        'dashboard.view',
        'config.manage',
        'log.view',
        'user.manage',
    ];

    public function run(): void
    {
        // 重置权限缓存
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 角色
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $operator = Role::findOrCreate('operator', 'web');
        Role::findOrCreate('customer', 'web'); // 买家，无后台权限

        // 运营：除用户管理外的日常运营权限
        $operator->syncPermissions([
            'product.view', 'product.create', 'product.update', 'category.manage',
            'order.view', 'order.ship', 'order.export',
            'refund.view', 'refund.process',
            'dashboard.view',
        ]);

        // 超级管理员：全部权限
        $superAdmin->syncPermissions(self::PERMISSIONS);

        // 初始账号
        $admin = SysUser::withTrashed()->updateOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('Admin@123'),
                'nickname' => '超级管理员',
                'status' => 1,
            ],
        );
        $admin->syncRoles([$superAdmin]);

        $operatorUser = SysUser::withTrashed()->updateOrCreate(
            ['username' => 'operator'],
            [
                'password' => Hash::make('Operator@123'),
                'nickname' => '运营账号',
                'status' => 1,
            ],
        );
        $operatorUser->syncRoles([$operator]);
    }
}
