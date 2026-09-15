<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 角色中文名：name 为英文标识（程序用，唯一），display_name 为中文名（展示用）。
 *
 * 本迁移幂等：重复执行不会重复加列，也不会覆盖已填写的中文名。
 */
return new class extends Migration
{
    /** 内置角色中文名（与 RoleController::ROLE_LABELS 对齐） */
    private const BUILTIN_DISPLAY_NAMES = [
        'super_admin' => '超级管理员',
        'operator' => '运营',
        'customer' => '买家',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        if (! Schema::hasColumn('roles', 'display_name')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('display_name', 64)->nullable()->after('name');
            });
        }

        // 回填内置角色中文名（不覆盖已有值）
        foreach (self::BUILTIN_DISPLAY_NAMES as $name => $displayName) {
            \DB::table('roles')
                ->where('name', $name)
                ->whereNull('display_name')
                ->update(['display_name' => $displayName]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'display_name')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('display_name');
            });
        }
    }
};
