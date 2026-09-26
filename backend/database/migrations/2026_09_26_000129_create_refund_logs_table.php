<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款事件日志表（append-only，全链路可追溯）
 *
 * 覆盖 apply/approve/channel_request/channel_response/channel_callback/query/
 * retry/success/failed/status_change 等节点。只追加不更新，与 refunds 主表的
 * 最终态快照互补，便于失败/成功/过程每个重要节点都可查、可复盘。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_id')->comment('关联 refunds.id');
            $table->string('type', 24)->comment('事件类型');
            $table->string('channel', 16)->nullable()->comment('渠道');
            $table->string('out_refund_no', 64)->nullable()->comment('幂等单号，跨事件串联');
            $table->json('request')->nullable()->comment('发往渠道的请求体');
            $table->json('response')->nullable()->comment('渠道响应原文');
            $table->string('channel_status', 32)->nullable()->comment('渠道返回细分状态');
            $table->string('actor_type', 16)->nullable()->comment('操作方 admin/system/customer');
            $table->unsignedBigInteger('actor_id')->nullable()->comment('操作人 ID（system 记 0）');
            $table->string('note', 255)->nullable()->comment('备注');
            $table->timestamp('created_at')->nullable()->comment('事件时间');

            $table->foreign('refund_id')->references('id')->on('refunds')->cascadeOnDelete();
            $table->index(['refund_id', 'type'], 'refund_logs_refund_type');
            $table->index(['out_refund_no'], 'refund_logs_out_refund_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_logs');
    }
};
