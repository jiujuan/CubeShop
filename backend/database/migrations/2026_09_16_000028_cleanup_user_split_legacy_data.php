<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段 3：清理用户表拆分遗留数据
 *
 * 阶段 2 已把买家代码全部切到 users 表，sys_user 中的买家记录自此成为僵尸数据。
 * 本迁移做三件事：
 *   1. 清理 spatie 角色关联表中买家的角色记录（买家不参与权限体系）
 *   2. 删除 sys_user 中的买家记录（数据完整保留在 users 表，未丢失）
 *   3. 移除已无持有者的 `customer` 角色
 *
 * 安全措施：
 *   - 仅当 users 与 sys_user 的 **ID 与 username 同时一致** 时才认定为同一账号并删除，
 *     避免任何误删；
 *   - 仍持有 super_admin / operator 角色的账号一律保留；
 *   - 幂等：可重复执行。
 *
 * 回滚说明：down() 为**有意的空操作** —— 买家原始数据始终保留在 users 表中，
 * 如需回退可重跑 `2026_09_16_000023_migrate_customers_to_users_table` 反向补种，
 * `customer` 角色可由 RolePermissionSeeder 重新创建，故无需（也不应）在本迁移里恢复。
 */
return new class extends Migration
{
    /** 后台角色（持有其一即视为管理员，不可删除） */
    private const ADMIN_ROLES = ['super_admin', 'operator'];

    /** spatie 多态关联中的模型类型 */
    private const MODEL_TYPE = 'App\Models\SysUser';

    public function up(): void
    {
        $this->cleanRolePivot();

        $this->deleteZombieBuyers();

        $this->deleteCustomerRole();
    }

    public function down(): void
    {
        // 有意为空：见类注释「回滚说明」
    }

    /** 1. 清理买家在 model_has_roles 中的记录 */
    private function cleanRolePivot(): void
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('users')) {
            return;
        }

        $buyerIds = DB::table('users')->pluck('id');

        if ($buyerIds->isEmpty()) {
            return;
        }

        $adminRoleIds = $this->adminRoleIds();

        DB::table('model_has_roles')
            ->where('model_type', self::MODEL_TYPE)
            ->whereIn('model_id', $buyerIds->all())
            ->when($adminRoleIds->isNotEmpty(), fn ($q) => $q->whereNotIn('role_id', $adminRoleIds->all()))
            ->delete();
    }

    /** 2. 删除 sys_user 中的买家僵尸记录 */
    private function deleteZombieBuyers(): void
    {
        if (! Schema::hasTable('sys_user') || ! Schema::hasTable('users')) {
            return;
        }

        $adminIds = DB::table('model_has_roles')
            ->where('model_type', self::MODEL_TYPE)
            ->whereIn('role_id', $this->adminRoleIds()->all())
            ->pluck('model_id')
            ->unique();

        // 仅当 ID 与 username 同时一致才认定为同一账号（防误删）；
        // 用 join 取候选 ID 再分批删除，避免依赖相关子查询删除（SQLite 兼容性更稳）。
        $ids = DB::table('sys_user')
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'sys_user.id')
                    ->on('users.username', '=', 'sys_user.username');
            })
            ->when($adminIds->isNotEmpty(), fn ($q) => $q->whereNotIn('sys_user.id', $adminIds->all()))
            ->pluck('sys_user.id')
            ->unique();

        $ids->chunk(500)->each(function ($chunk) {
            DB::table('sys_user')->whereIn('id', $chunk->all())->delete();
        });
    }

    /** 3. 移除已无持有者的 customer 角色 */
    private function deleteCustomerRole(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $role = DB::table('roles')->where('name', 'customer')->where('guard_name', 'web')->first();

        if (! $role) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
        }

        if (Schema::hasTable('model_has_roles')) {
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();
        }

        DB::table('roles')->where('id', $role->id)->delete();
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function adminRoleIds(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('roles')) {
            return collect();
        }

        return DB::table('roles')->whereIn('name', self::ADMIN_ROLES)->pluck('id');
    }
};
