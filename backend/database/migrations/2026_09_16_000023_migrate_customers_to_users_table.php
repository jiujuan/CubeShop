<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段 1：把 sys_user 中的买家记录按原 ID 搬迁到 users 表
 *
 * 买家识别：持有 spatie `customer` 角色的 SysUser 记录
 *   （V1.1 之前注册接口执行 assignRole('customer')，因此该判据完整覆盖存量买家）
 *
 * 关键约束：
 *  - **保持原 ID**：业务表（orders / cart_items / user_addresses 等）的 user_id
 *    无需更新，规避 10 张表的数据改写。
 *  - **幂等**：insertOrIgnore，重复执行不会报错也不会产生重复行。
 *  - **不改代码**：本阶段应用仍读写 sys_user，线上行为不变。
 *  - **可回滚**：down() 仅移除与 sys_user 买家重复的记录，不误删新注册用户。
 *
 * 方案文档：docs/design/CubeShop_UserTable_Split_Analysis.md（阶段 1）
 */
return new class extends Migration
{
    /** spatie morph 类型：当前项目仅 SysUser 使用 HasRoles */
    private const MODEL_TYPE = 'App\Models\SysUser';

    private const CUSTOMER_ROLE = 'customer';

    /** 需要搬迁的列（users 与 sys_user 同构部分） */
    private const COPY_COLUMNS = [
        'id', 'username', 'email', 'phone', 'password', 'nickname', 'avatar',
        'status', 'last_login_at', 'last_login_ip', 'created_at', 'updated_at', 'deleted_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('sys_user')) {
            return;
        }

        $buyerIds = $this->customerIds();

        if ($buyerIds->isEmpty()) {
            return; // 全新环境尚未 seed 角色 / 无买家，无需搬迁
        }

        DB::table('sys_user')
            ->whereIn('id', $buyerIds->all())
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                $payload = [];
                foreach ($rows as $row) {
                    $item = [];
                    foreach (self::COPY_COLUMNS as $column) {
                        $item[$column] = $row->{$column};
                    }
                    $payload[] = $item;
                }

                DB::table('users')->insertOrIgnore($payload);
            });

        $this->syncSequence();
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $buyerIds = $this->customerIds();

        if ($buyerIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $buyerIds->all())->delete();
        }
    }

    /**
     * 取持有 customer 角色的 SysUser ID 集合（含软删除记录）
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function customerIds(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return collect();
        }

        $roleIds = DB::table('roles')->where('name', self::CUSTOMER_ROLE)->pluck('id');

        if ($roleIds->isEmpty()) {
            return collect();
        }

        return DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds->all())
            ->where('model_type', self::MODEL_TYPE)
            ->pluck('model_id')
            ->unique()
            ->values();
    }

    /**
     * 对齐自增序列，避免新注册买家的 ID 与搬迁过来的存量 ID 冲突
     * （PostgreSQL 在显式插入 ID 时不会推进序列；SQLite AUTOINCREMENT 会自动跟进）
     */
    private function syncSequence(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $max = (int) DB::table('users')->max('id');
            if ($max > 0) {
                DB::statement('SELECT setval(pg_get_serial_sequence(\'users\', \'id\'), ?)', [$max]);
            }
        }
    }
};
