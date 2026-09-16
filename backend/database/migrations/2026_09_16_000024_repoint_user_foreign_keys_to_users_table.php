<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段 2：把买家相关外键从 sys_user 重指向 users
 *
 * 涉及 3 张真正带外键约束的表（另有若干表仅有 user_id 列、无约束）：
 *   orders.user_id         → users.id
 *   cart_items.user_id     → users.id（级联删除）
 *   user_addresses.user_id → users.id（级联删除）
 *
 * 为什么必须重建：阶段 3 会清空 sys_user 中的买家记录，
 * 若不重指向，新订单/购物车/地址写入会因外键指向 sys_user 而失败。
 *
 * 说明：
 * - 先 dropForeign（SQLite 通过表重建实现），再 add foreign；
 * - 使用列名数组而非约束名，SQLite 不支持按约束名删除外键；
 * - 保持原有级联语义：orders 无级联，cart_items / user_addresses 级联删除。
 */
return new class extends Migration
{
    /** 表 => 是否级联删除 */
    private const TABLES = [
        'orders' => false,
        'cart_items' => true,
        'user_addresses' => true,
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $cascade) {
            $this->repoint($table, $cascade, 'users');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $cascade) {
            $this->repoint($table, $cascade, 'sys_user');
        }
    }

    /**
     * 重建 user_id 外键指向目标表
     */
    private function repoint(string $table, bool $cascade, string $target): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($target)) {
            return;
        }

        // 1) 删除旧外键
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
        });

        // 2) 建立指向目标表的新外键
        Schema::table($table, function (Blueprint $blueprint) use ($cascade, $target) {
            $foreign = $blueprint->foreign('user_id')->references('id')->on($target);

            if ($cascade) {
                $foreign->cascadeOnDelete();
            }
        });
    }
};
