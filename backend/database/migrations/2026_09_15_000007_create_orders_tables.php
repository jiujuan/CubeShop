<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单主表 / 订单明细（含商品快照）
 * 对应设计文档：2.6 orders、order_items
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_no', 32)->unique()->comment('业务订单号');
            $table->unsignedBigInteger('user_id')->comment('下单用户');
            $table->string('status', 32)->comment('pending_payment/paid/shipped/completed/cancelled/refunding/refunded');
            $table->decimal('total_amount', 12, 2)->comment('商品总金额');
            $table->decimal('freight_amount', 12, 2)->default(0)->comment('运费');
            $table->decimal('pay_amount', 12, 2)->comment('应付金额');
            $table->jsonb('address_snapshot')->comment('收货地址快照');
            $table->string('remark', 255)->nullable()->comment('用户备注');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('sys_user');
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->comment('订单');
            $table->unsignedBigInteger('product_id')->nullable()->comment('原商品 ID（可空，防删除）');
            $table->unsignedBigInteger('sku_id')->nullable()->comment('原 SKU ID');
            $table->string('product_title', 255)->comment('快照标题');
            $table->jsonb('sku_specs')->nullable()->comment('快照规格');
            $table->string('sku_image', 512)->nullable()->comment('快照图片');
            $table->decimal('price', 12, 2)->comment('下单单价');
            $table->integer('quantity')->comment('数量');
            $table->decimal('total_amount', 12, 2)->comment('小计');
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
