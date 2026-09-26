<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 积分账户（会员成长计划 S1）
 *
 * 设计文档：docs/design/points-checkin-membership.md §5
 *
 * 每用户一行，与 user_balances 同体例：账户表存**余额冗余**，真实流水在 user_point_logs。
 * 积分是整数（不存在小数分），故不用 decimal。
 *
 * ### 三个数值字段的分工
 *
 * - balance：**可用**积分，能被抵扣/消费的那部分
 * - frozen：**冻结**积分（S5 起由下单占用产生），已从 balance 扣出、尚未确认消耗
 * - total_earn / total_spend：累计获得 / 累计消耗，只增不减，用于对账与运营统计
 *
 * 「总额（含冻结）」= balance + frozen。
 *
 * ⚠️ 冻结两段式是 D1 的既定口径：下单时 balance → frozen，支付成功才真正扣减，
 *    取消/超时/支付失败则回退。账户表从一开始就留 frozen 列，S5 无需再改表。
 *
 * ⚠️ 不建外键（与 user_balances 同体例）：本表由应用层保证唯一，
 *    外键只会让清理用户数据变得束手束脚。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_points', function (Blueprint $table) {
            // 主键即 user_id：与 user_balances 同体例，一个用户永远只有一个积分账户
            $table->unsignedBigInteger('user_id')->primary();
            $table->integer('balance')->default(0)->comment('可用积分');
            $table->integer('frozen')->default(0)->comment('冻结积分（下单占用，S5 起使用）');
            $table->integer('total_earn')->default(0)->comment('累计获得积分');
            $table->integer('total_spend')->default(0)->comment('累计消耗积分');
            $table->integer('version')->default(0)->comment('乐观锁版本号');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_points');
    }
};
