<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单状态流水表（V1.1 E02-A / T-001）
 *
 * 唯一写入点：OrderLogService::record()，由 OrderService::transitionTo() 调用。
 * 记录「谁、从什么状态、到什么状态、什么原因、何时」，供确认收货时间轴与问题排查使用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->comment('订单 ID');
            $table->string('from_status', 32)->nullable()->comment('变更前状态（创建订单时为空）');
            $table->string('to_status', 32)->comment('变更后状态');
            $table->string('operator_type', 16)->default('system')->comment('user/admin/system');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人 ID（system 为空）');
            $table->string('remark', 255)->nullable()->comment('变更说明');
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_logs');
    }
};
