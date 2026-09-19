<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 发货单行项目（WMS 计划 P1 / 设计文档 §4.1）
 *
 * 建单时从 `order_items` 快照落库：平台 SKU 编码与 WMS 货品编码都固化为字符串，
 * 避免商品/SKU 后续被改名或下架时影响已推送单据（与 order_items 同思路）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fulfillment_order_id');
            $table->unsignedBigInteger('sku_id')->nullable()->comment('原 SKU ID（可空，防删除）');
            $table->string('platform_sku_code', 64)->comment('平台 SKU 编码快照');
            $table->string('wms_sku_code', 64)->comment('WMS 货品编码（resolveSkuCode 解析，推送用）');
            $table->string('product_name', 255)->nullable()->comment('商品名称快照');
            $table->unsignedInteger('qty')->comment('应发数量');
            $table->unsignedInteger('shipped_qty')->default(0)->comment('实发数量（回传）');
            $table->string('barcode', 64)->nullable()->comment('条码（手工映射可填）');
            $table->timestamp('created_at')->nullable();

            $table->foreign('fulfillment_order_id')
                ->references('id')->on('fulfillment_orders')
                ->cascadeOnDelete();
            $table->index('fulfillment_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_order_items');
    }
};
