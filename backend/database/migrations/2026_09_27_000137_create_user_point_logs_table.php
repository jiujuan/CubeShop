<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 积分流水（会员成长计划 S1）
 *
 * 设计文档：docs/design/points-checkin-membership.md §5
 *
 * 只增不改、不删：每一行都是一次积分变动的凭证，账户余额可由流水重算校验。
 *
 * ### 为什么记两个变动量
 *
 * 积分有「可用 / 冻结」两个池子，一笔业务可能只挪位置而不改总量：
 *
 * | 业务 | points（可用变动） | frozen_points（冻结变动） |
 * |---|---|---|
 * | 获得（返分/签到/人工加） | +n | 0 |
 * | 消耗（抵扣确认，S5） | 0 | −n |
 * | 冻结（下单占用，S5） | −n | +n |
 * | 释放（取消/超时，S5） | +n | −n |
 *
 * 只记一个量的话，「冻结→确认消耗」这笔就会被记成 0 分流水而丢失信息，
 * 对账时无法解释 frozen 为什么减少。故两个变动量都落盘，并各自存 before/after。
 *
 * ### biz_key 幂等
 *
 * 唯一索引，值形如 `order:12:earn` / `refund:34:return`。
 * 支付回调重放、退款回调重放都会走到发分/退分，靠它保证一次业务只动一次积分。
 * 人工调整与签到允许不传（前者靠操作日志追溯，后者靠 user_checkins 唯一键）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_point_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 24)->comment('signin/earn/consume/freeze/release/refund_return/admin_adjust');
            $table->integer('points')->comment('可用积分变动，正=入账 负=出账');
            $table->integer('frozen_points')->default(0)->comment('冻结积分变动，正=增加 负=减少');
            $table->integer('balance_before');
            $table->integer('balance_after');
            $table->integer('frozen_before')->default(0);
            $table->integer('frozen_after')->default(0);
            $table->string('related_type', 32)->nullable()->comment('order/refund/checkin/user');
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('biz_key', 64)->nullable()->comment('业务幂等键，如 order:12:earn');
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('后台调整时的管理员 sys_user.id');
            $table->timestamp('created_at')->nullable();

            $table->unique('biz_key');
            $table->index(['user_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_point_logs');
    }
};
