<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步「收银台与支付渠道」新增权限码（收银台方案 §10）
 *
 * - payment.channel.manage：支付渠道配置（仅超管）
 * - payment.offline.review：线下转账 / 线下充值核账（运营 + 超管）
 * - balance.recharge.view ：充值单列表与详情，只读（运营 + 超管）
 *
 * 本迁移幂等，重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'payment.channel.manage',
        'payment.offline.review',
        'balance.recharge.view',
    ];

    /** 运营角色权限范围（与 RolePermissionSeeder 保持一致，只增不减） */
    private const OPERATOR_PERMISSIONS = [
        'product.view', 'product.create', 'product.update', 'category.manage',
        'order.view', 'order.ship', 'order.export',
        'refund.view', 'refund.process',
        'dashboard.view',
        'address.view',
        'review.manage', 'report.view', 'inventory.manage',
        'payment.view', 'order.log',
        // 收银台：核账与充值单查看；渠道配置（payment.channel.manage）为超管专属
        'payment.offline.review', 'balance.recharge.view',
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

        Role::findOrCreate('super_admin', 'web')
            ->syncPermissions(RolePermissionSeeder::PERMISSIONS);

        Role::findOrCreate('operator', 'web')
            ->givePermissionTo(self::OPERATOR_PERMISSIONS);

        Role::findOrCreate('customer', 'web');

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

    private function permissionTablesExist(): bool
    {
        try {
            return Permission::query()->count() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
