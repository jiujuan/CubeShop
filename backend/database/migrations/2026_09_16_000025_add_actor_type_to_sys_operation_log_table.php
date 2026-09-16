<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段 2：为 sys_operation_log 增加 actor_type，明确操作人来源表
 *
 * 背景：user_id 是混合语义列 —— AuthController 的登录/改密/重置密码对
 * 买家与后台管理员都会写日志；ProfileController 也会写买家资料变更。
 * 买家表拆分后两张表的 ID 各自独立（可能撞号），必须显式区分，否则后台审计会归属错误。
 *
 * 取值：admin（后台管理员，指向 sys_user）/ customer（买家，指向 users）
 * 历史数据：加列默认 'admin'，再按「user_id 出现在 users 表中」回填为 customer。
 *
 * 注：回填为启发式判定。若历史上存在「管理员 ID 恰好等于某个买家 ID」的日志，
 * 会被误标为 customer；当前开发库管理员 ID 为 1/2、买家 ID 从 3 起，不存在该情形。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sys_operation_log')) {
            return;
        }

        if (! Schema::hasColumn('sys_operation_log', 'actor_type')) {
            Schema::table('sys_operation_log', function (Blueprint $table) {
                $table->string('actor_type', 16)
                    ->default('admin')
                    ->comment('操作人来源：admin=后台管理员 / customer=买家')
                    ->after('user_id');

                $table->index('actor_type');
            });
        }

        // 回填：操作人 ID 存在对应买家记录的，标记为 customer
        if (Schema::hasTable('users')) {
            DB::table('sys_operation_log')
                ->whereIn('user_id', function ($query) {
                    $query->select('id')->from('users');
                })
                ->update(['actor_type' => 'customer']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sys_operation_log') || ! Schema::hasColumn('sys_operation_log', 'actor_type')) {
            return;
        }

        Schema::table('sys_operation_log', function (Blueprint $table) {
            $table->dropIndex(['actor_type']);
            $table->dropColumn('actor_type');
        });
    }
};
