<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款纠纷/申诉表
 *
 * 独立于 refunds 主表，承载「买家对退款结论/退货认定有异议 → 平台介入调解」的流程。
 * 裁决可触发退款动作，但终态一律由 RefundService 驱动（网关纯净，状态机唯一入口）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_disputes', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 24)->unique()->comment('对外 ID');
            $table->unsignedBigInteger('refund_id')->comment('关联 refunds.id');
            $table->unsignedBigInteger('order_id')->comment('冗余订单 ID（便于按单查纠纷）');
            $table->unsignedBigInteger('user_id')->nullable()->comment('发起买家');
            $table->string('reason_code', 32)->comment('争议原因码');
            $table->string('description', 1000)->nullable()->comment('买家描述');
            $table->json('evidence')->nullable()->comment('举证图片（相对路径）');
            $table->string('status', 24)->comment('纠纷状态');
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->comment('指派处理人 sys_user.id');
            $table->string('resolution', 32)->nullable()->comment('裁决结论码');
            $table->string('resolution_note', 1000)->nullable()->comment('裁决说明');
            $table->string('refund_action', 32)->nullable()->comment('裁决触发动作');
            $table->unsignedBigInteger('resolved_by')->nullable()->comment('裁决人 sys_user.id');
            $table->timestamp('resolved_at')->nullable()->comment('裁决时间');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->timestamps();

            $table->foreign('refund_id')->references('id')->on('refunds')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_admin_id')->references('id')->on('sys_user')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('sys_user')->nullOnDelete();
            $table->index(['refund_id'], 'refund_disputes_refund');
            $table->index(['order_id'], 'refund_disputes_order');
            $table->index(['user_id'], 'refund_disputes_user');
            $table->index(['status'], 'refund_disputes_status');
            $table->index(['status', 'created_at'], 'refund_disputes_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_disputes');
    }
};
