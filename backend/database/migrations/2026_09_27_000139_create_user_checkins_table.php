<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户签到记录（会员成长计划 S2）
 *
 * 设计文档：docs/design/points-checkin-membership.md §7
 *
 * ### 为什么要有这张表，而不是只在积分流水里记一笔
 *
 * 签到有三个「积分流水表达不了」的语义：
 * 1. **同日只能一次** —— 靠 unique(user_id, checkin_date) 由数据库兜底，
 *    比「先查再写」可靠（并发下两个请求可能同时查到「今天没签」）；
 * 2. **连续天数** —— streak 是签到自己的状态，写进积分流水会让流水承担业务语义；
 * 3. **补签留痕** —— is_backfill + created_by 记录「谁把哪天补上了」。
 *
 * ### 连续天数为什么落库而不是每次实时算
 *
 * 实时算（查最近 N 天记录数）在补签、跨天、断签重来时都会算错：
 * 补签昨天以后「今天」到底连续几天？取决于补的那天是否与原序列衔接。
 * 落库后补签只影响补的那一行及其后继，语义清晰可审计。
 *
 * ⚠️ checkin_date 用 App\Casts\DateOnly（模型侧）：SQLite 无原生 DATE，
 *    Laravel 默认 date cast 会写成 `Y-m-d 00:00:00`，导致按日等值查询全部落空。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_checkins', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->date('checkin_date')->comment('签到日期（业务日期，非创建时间）');
            $table->unsignedSmallInteger('streak')->comment('当日连续天数（7 日循环，1~7）');
            $table->unsignedInteger('points')->comment('当日实发积分');
            $table->boolean('is_backfill')->default(false)->comment('是否后台补签');
            // 不建外键：与 inventory_checks.created_by 同体例，避免删除账号被约束挡住
            $table->unsignedBigInteger('created_by')->nullable()->comment('补签操作人 sys_user.id');
            $table->timestamps();

            // 同日幂等的最终防线（并发下两个请求同时签到，只有一个能成功）
            $table->unique(['user_id', 'checkin_date'], 'uk_user_checkin_date');
            $table->index(['user_id', 'checkin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_checkins');
    }
};
