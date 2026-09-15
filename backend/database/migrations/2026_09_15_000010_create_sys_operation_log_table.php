<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 操作日志
 * 对应设计文档：2.1.3 sys_operation_log
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_operation_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->comment('操作人');
            $table->string('module', 64)->nullable()->comment('模块（product/order/...）');
            $table->string('action', 64)->nullable()->comment('动作（create/update/ship/...）');
            $table->string('target_type', 64)->nullable()->comment('目标类型');
            $table->unsignedBigInteger('target_id')->nullable()->comment('目标 ID');
            $table->text('content')->nullable()->comment('变更摘要/JSON');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_operation_log');
    }
};
