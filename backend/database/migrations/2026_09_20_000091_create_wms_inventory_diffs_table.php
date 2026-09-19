<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS 库存差异（WMS 计划 P5 / F3、F4）
 *
 * 每日对账把「平台可售库存 vs WMS 可用量」的差值固化成一行待办，
 * 由运营 `resolve`（校准 / 忽略）后关闭。差异本身**只是记录**，不会自动改库存。
 *
 * 状态：`pending`（待处理）/ `resolved`（已校准）/ `ignored`（已忽略）。
 * 同一仓库同一 SKU 同时至多一条 `pending`，避免每日对账把同一差异重复开单
 * （PG 与 SQLite 均支持部分唯一索引）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_inventory_diffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('sku_id');
            $table->string('wms_sku_code', 100)->default('');
            $table->integer('platform_qty')->default(0);
            $table->integer('wms_qty')->default(0);
            // 冗余 diff = wms_qty - platform_qty：列表可直接按差异绝对值排序
            $table->integer('diff')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('remark', 500)->default('');
            $table->timestamps();

            $table->index(['warehouse_id', 'status'], 'wms_inv_diff_wh_status_index');
            $table->index('status', 'wms_inv_diff_status_index');

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
        });

        DB::statement(
            "CREATE UNIQUE INDEX wms_inventory_diffs_pending_unique
             ON wms_inventory_diffs (warehouse_id, sku_id) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_inventory_diffs');
    }
};
