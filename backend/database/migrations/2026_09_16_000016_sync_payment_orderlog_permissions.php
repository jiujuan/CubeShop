<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步「支付管理与订单流水」新增权限码，并补齐角色授权。
 *
 * 背景：payment.view / payment.manage / order.log 三个权限码新增在
 * RolePermissionSeeder 中，存量环境（如开发库 pgsql）不会重跑 seeder，
 * 会导致后台支付管理与订单流水页面 403。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    /** 本次新增的权限码（用于 down 时精确回收） */
    private const NEW_PERMISSIONS = [
        'payment.view',
        'payment.manage',
        'order.log',
    ];

    /**
     * 运营角色的权限范围（与 RolePermissionSeeder 保持一致，只增不减）
     */
    private const OPERATOR_PERMISSIONS = [
        'product.view', 'product.create', 'product.update', 'category.manage',
        'order.view', 'order.ship', 'order.export',
        'refund.view', 'refund.process',
        'dashboard.view',
        'address.view',
        'review.manage', 'report.view', 'inventory.manage',
        'payment.view', 'order.log',
    ];

    public function up(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (RolePermissionSeeder::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 超级管理员：全量对齐（防止后续新增权限再次遗漏）
        Role::findOrCreate('super_admin', 'web')
            ->syncPermissions(RolePermissionSeeder::PERMISSIONS);

        // 运营：补齐查看类权限；payment.manage 为超管专属，不在此授予
        Role::findOrCreate('operator', 'web')
            ->givePermissionTo(self::OPERATOR_PERMISSIONS);

        Role::findOrCreate('customer', 'web'); // 买家，无后台权限

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $existing = Permission::whereIn('name', self::NEW_PERMISSIONS)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        if ($existing !== []) {
            Role::where('name', 'super_admin')->first()?->revokePermissionTo($existing);
            Role::where('name', 'operator')->first()?->revokePermissionTo($existing);
            Permission::whereIn('name', $existing)->where('guard_name', 'web')->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** 判断 spatie 权限相关数据表是否已存在 */
    private function permissionTablesExist(): bool
    {
        try {
            return Permission::query()->count() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
