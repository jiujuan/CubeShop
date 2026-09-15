<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 库存（按 SKU）与库存流水
 * 对应设计文档：2.4 inventories、inventory_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sku_id')->unique()->comment('SKU');
            $table->integer('stock')->default(0)->comment('可售库存');
            $table->integer('locked_stock')->default(0)->comment('锁定库存（下单未支付）');
            $table->integer('version')->default(0)->comment('乐观锁版本号');
            $table->timestamp('updated_at')->nullable();

            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
        });

        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sku_id')->comment('SKU');
            $table->string('change_type', 32)->comment('lock/unlock/deduct/increase/adjust');
            $table->integer('change_qty')->comment('变更数量（可正可负）');
            $table->integer('before_stock')->nullable()->comment('变更前可售');
            $table->integer('after_stock')->nullable()->comment('变更后可售');
            $table->integer('before_locked')->nullable();
            $table->integer('after_locked')->nullable();
            $table->string('biz_type', 32)->nullable()->comment('order/refund/adjust/cancel');
            $table->unsignedBigInteger('biz_id')->nullable()->comment('关联业务 ID');
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人（系统为 null）');
            $table->timestamp('created_at')->nullable();

            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
            $table->index(['sku_id', 'created_at']);
            $table->index(['biz_type', 'biz_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
        Schema::dropIfExists('inventories');
    }
};
