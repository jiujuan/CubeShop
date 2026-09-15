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
        'address.view',
        'address.manage',
        // 支付与订单流水（后台 payment.view / payment.manage / order.log）
        'payment.view',
        'payment.manage',
        'order.log',
        // V1.1 新增权限码（T-017 / T-020 / T-022）
        'review.manage',
        'report.view',
        'account.manage',
        'role.manage',
        'inventory.manage',
    ];

    public function run(): void
    {
        // 重置权限缓存
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 角色：name 为英文标识（程序用），display_name 为中文名（展示用）
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $operator = Role::findOrCreate('operator', 'web');
        $customer = Role::findOrCreate('customer', 'web'); // 买家，无后台权限

        foreach ([
            [$superAdmin, '超级管理员'],
            [$operator, '运营'],
            [$customer, '买家'],
        ] as [$role, $displayName]) {
            if ($role->display_name !== $displayName) {
                $role->display_name = $displayName;
                $role->save();
            }
        }

        // 运营：除用户管理外的日常运营权限（地址仅可查看核对，代改需超管授权）
        // 账号/角色管理（account.manage / role.manage）为超管专属，运营默认不授予
        $operator->syncPermissions([
            'product.view', 'product.create', 'product.update', 'category.manage',
            'order.view', 'order.ship', 'order.export',
            'refund.view', 'refund.process',
            'dashboard.view',
            'address.view',
            'review.manage', 'report.view', 'inventory.manage',
            // 支付只读（查看支付单/支付日志）+ 订单流水；关闭支付单需超管
            'payment.view', 'order.log',
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
