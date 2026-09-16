<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段 2：为 notifications 增加 receiver_type，明确收件人来源表
 *
 * 背景：notifications.user_id 与 sys_operation_log.user_id 同属混合语义列 ——
 * 买家通知（订单/退款/评价/改密）写入买家 ID，而库存预警
 * （NotificationService::sendToRole('operator', ...)）写入后台管理员 ID。
 * 买家表拆分后两侧 ID 可能撞号，买家会在自己的通知列表里读到发给运营的库存预警。
 *
 * 取值：customer（买家，指向 users）/ admin（后台管理员，指向 sys_user）
 * 历史数据：加列默认 'customer'，再按「user_id 不在 users 表中」回填为 admin。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        if (! Schema::hasColumn('notifications', 'receiver_type')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('receiver_type', 16)
                    ->default('customer')
                    ->comment('收件人来源：customer=买家 / admin=后台管理员')
                    ->after('user_id');

                $table->index(['receiver_type', 'user_id', 'is_read'], 'notifications_receiver_read_index');
            });
        }

        // 回填：收件人不是买家的，一律视为后台管理员
        if (Schema::hasTable('users')) {
            DB::table('notifications')
                ->whereNotIn('user_id', function ($query) {
                    $query->select('id')->from('users');
                })
                ->update(['receiver_type' => 'admin']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasColumn('notifications', 'receiver_type')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_receiver_read_index');
            $table->dropColumn('receiver_type');
        });
    }
};
