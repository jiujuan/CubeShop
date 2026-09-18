<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS SKU 映射（WMS 计划 P0 / F6）
 *
 * 平台 SKU（product_skus.sku_code）↔ WMS 货品编码的翻译表，按仓库隔离
 * （同一 SKU 在不同仓库可映射到不同 WMS 编码，故 unique(warehouse_id, sku_id)）。
 *
 * sku_mapping_mode=same 时不查本表（直接回落平台 sku_code）；
 * =manual 时必须在表中命中，否则拒绝推送（WmsConfigService::resolveSkuCode 抛 40009）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_sku_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('sku_id');
            // 冗余平台 sku_code：导入 CSV 时便于按 code 定位，也方便排查对照
            $table->string('platform_sku_code', 64);
            // WMS 侧货品编码（菜鸟 itemCode / 京东 skuId）
            $table->string('wms_sku_code', 64);
            // 条码（可选）
            $table->string('barcode', 64)->nullable();
            // 1=启用 0=停用；停用视同未映射
            $table->tinyInteger('status')->default(1);
            $table->timestamps();

            $table->unique(['warehouse_id', 'sku_id']);
            $table->index(['warehouse_id', 'status']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('sku_id')->references('id')->on('product_skus')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_sku_mappings');
    }
};
