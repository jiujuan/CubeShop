<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 同步短信渠道（短信渠道计划 第一期）新增权限码，并补齐角色授权。
 *
 * 新增：sms.manage（凭证配置、渠道启用切换、测试发送、发送记录查看）
 *      sms.view（只读：查看渠道与发送记录，预留给将来的只读角色）
 *
 * 存量环境不会重跑 seeder，缺少权限码会导致短信配置页 403。
 *
 * ⚠️ operator **两个都不授予**：短信凭证等同于花钱的钥匙（AccessKey Secret），
 *    且渠道切换会让全站验证码发送行为整体变化，与 search.manage 同体例保持超管专属。
 *    `sms.view` 当前无人持有，是为将来「只看日志不发短信」的只读角色预留。
 *
 * 本迁移幂等：重复执行不会产生重复数据。
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'sms.view',
        'sms.manage',
    ];

    /** 运营可获得的权限（当前为空：短信配置保持超管专属） */
    private const OPERATOR_PERMISSIONS = [];

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
