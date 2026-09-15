<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款申请
 * 对应设计文档：2.8 refunds
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('refund_no', 64)->unique()->comment('退款单号');
            $table->unsignedBigInteger('order_id')->comment('订单');
            $table->string('order_no', 32)->comment('冗余订单号');
            $table->unsignedBigInteger('user_id')->comment('申请用户');
            $table->decimal('amount', 12, 2)->comment('退款金额');
            $table->string('reason', 255)->nullable()->comment('用户原因');
            $table->string('status', 32)->comment('pending/approved/rejected/success/failed');
            $table->string('admin_remark', 255)->nullable()->comment('后台备注');
            $table->unsignedBigInteger('processed_by')->nullable()->comment('处理人');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
