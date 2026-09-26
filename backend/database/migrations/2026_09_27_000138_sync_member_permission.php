<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步会员与积分新增权限码，并补齐角色授权。
 *
 * 新增：member.view（查看积分账户/流水）、member.manage（人工调整积分、补签、改规则）
 *
 * ### 为什么两档都给运营
 *
 * `member.manage` 能直接给买家加积分，等于间接发钱，看似该收紧给超管。但运营手上
 * 已经有更重的权限：`payment.offline.review`（线下转账核账 = 直接加**真金白银**的余额）、
 * `marketing.manage`（无成本发券）。积分调整的金额量级与可追溯性都不弱于这两项
 * （每笔落 user_point_logs + 操作日志 + 原因必填），再单独收紧没有实际意义，
 * 只会让日常会员运营事事打扰超管。
 *
 * 存量环境不会重跑 seeder，缺少权限码会导致积分入口 403。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'member.view',
        'member.manage',
    ];

    /** 运营可获得的权限 */
    private const OPERATOR_PERMISSIONS = [
        'member.view',
        'member.manage',
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

        if (self::OPERATOR_PERMISSIONS !== []) {
            Role::findOrCreate('operator', 'web')
                ->givePermissionTo(self::OPERATOR_PERMISSIONS);
        }

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
