<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单侧挂载 WMS 字段（WMS 计划 P1 / F3）
 *
 * - `warehouse_id`：履约仓。下单/支付后由履约链路回填，未接 WMS 时保持 null（旧行为不变）；
 * - `fulfillment_status`：发货单状态冗余，供后台列表筛选（免联表；
 *   真实状态以 `fulfillment_orders.status` 为准，本列只是索引副本）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->comment('履约仓（WMS）');
            $table->string('fulfillment_status', 32)->nullable()->comment('发货单状态冗余（列表筛选用）');

            $table->index('warehouse_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['warehouse_id']);
            $table->dropColumn(['warehouse_id', 'fulfillment_status']);
        });
    }
};
