<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退货入库单行项目（WMS 计划 P4 / 设计文档 §4.1）
 *
 * 建单时从 refunds.return_details 快照：编码固化字符串，商品改名/下架不影响已推送单据。
 * `received_qty` / `inventory_type`（ZP 正品 / CC 残次）由 WMS 收货回传填写。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_inbound_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_inbound_order_id');
            $table->unsignedBigInteger('sku_id')->nullable()->comment('原 SKU ID（可空，防删除）');
            $table->string('platform_sku_code', 64)->comment('平台 SKU 编码快照');
            $table->string('wms_sku_code', 64)->comment('WMS 货品编码（resolveSkuCode 解析，推送用）');
            $table->string('product_name', 255)->nullable()->comment('商品名称快照');
            $table->unsignedInteger('qty')->comment('应退数量');
            $table->unsignedInteger('received_qty')->default(0)->comment('实收数量（回传）');
            $table->string('inventory_type', 16)->default('ZP')->comment('实收质检：ZP 正品 / CC 残次');
            $table->string('barcode', 64)->nullable()->comment('条码（手工映射可填）');
            $table->timestamp('created_at')->nullable();

            $table->foreign('return_inbound_order_id')
                ->references('id')->on('return_inbound_orders')
                ->cascadeOnDelete();
            $table->index('return_inbound_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_inbound_order_items');
    }
};
