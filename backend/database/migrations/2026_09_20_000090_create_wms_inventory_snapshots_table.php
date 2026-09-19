<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS 库存快照（WMS 计划 P5 / F2）
 *
 * 定时同步的落点：**只记录，不直接改平台可售库存**——WMS 侧数据可能脏
 * （在途未回传、盘点未同步），直接覆盖会造成超卖。差异由对账任务
 * （`wms_inventory_diffs`）显式暴露，交人工或 `--apply` 显式校准。
 *
 * 一个仓库一个 SKU 只留最新一行：`UNIQUE(warehouse_id, sku_id)`，
 * 重复同步走 upsert 覆盖（不是累加）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('sku_id');
            // 查询时用的 WMS 货品编码（冗余留存，便于排障「当时用哪个码查的」）
            $table->string('wms_sku_code', 100)->default('');
            $table->integer('available_qty')->default(0);
            $table->integer('locked_qty')->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['warehouse_id', 'sku_id'], 'wms_inv_snap_wh_sku_unique');
            $table->index(['warehouse_id', 'synced_at'], 'wms_inv_snap_wh_synced_index');

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_inventory_snapshots');
    }
};
