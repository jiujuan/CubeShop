<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 发货单主表（WMS 计划 P1 / 设计文档 §4.1）
 *
 * 平台履约的唯一载体：订单支付成功 → 生成发货单 → 推送 WMS → 回传运单 → 订单发货。
 * 字段分三组：业务标识 / 推送（push_*）/ 回传（tracking_*、shipped_at）。
 *
 * 唯一性（F9「一单一发货单」）：
 * - `outbound_no` 全局唯一；
 * - `order_id` 用**部分唯一索引**（partial unique index，排除 cancelled）——
 *   既要保证同一订单不会并存两张活跃发货单，又要支持「退款被驳回、订单回到已支付
 *   后重新受理」时重建发货单（此场景计划 Step1 的普通 UNIQUE(order_id) 无法表达）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->comment('平台订单');
            $table->string('order_no', 32)->comment('订单号冗余（列表筛选免联表）');
            $table->string('outbound_no', 32)->comment('平台出库单号 FO+日期+随机段');
            $table->unsignedBigInteger('warehouse_id')->comment('履约仓');
            $table->string('provider', 16)->nullable()->comment('服务商快照 cainiao/jd_cloud');
            $table->string('status', 32)
                ->comment('created/pending_push/pushing/pushed/picking/packed/shipped/completed/cancelled/exception/push_failed');

            // ---- WMS 回传 ----
            $table->string('wms_outbound_no', 64)->nullable()->comment('WMS 侧出库单号');
            $table->string('tracking_no', 64)->nullable()->comment('运单号');
            $table->string('carrier_code', 32)->nullable()->comment('快递公司编码');
            $table->string('carrier_name', 64)->nullable()->comment('快递公司名称快照');

            // ---- 推送状态 ----
            $table->string('push_request_id', 64)->nullable()->comment('推送幂等键（Job 生成，重试沿用）');
            $table->unsignedInteger('push_times')->default(0)->comment('已推送次数');
            $table->timestamp('last_push_at')->nullable();
            $table->text('last_push_error')->nullable()->comment('最近一次推送失败原因');

            // ---- 时间与异常 ----
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('exception_reason', 255)->nullable()->comment('建单/推送异常原因（人工介入线索）');

            $table->json('buyer_info')->nullable()->comment('收货人快照（来自 orders.address_snapshot）');
            $table->json('shipping_info')->nullable()->comment('配送要求（订单备注等）');
            $table->json('extend')->nullable();
            $table->timestamps();

            $table->unique('outbound_no');
            $table->index(['warehouse_id', 'status']);
            $table->index('order_no');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });

        // 一单一活跃发货单（已取消的不占用名额，允许重建）。PG 与 SQLite 均支持部分索引。
        DB::statement(
            "CREATE UNIQUE INDEX fulfillment_orders_active_order_unique
             ON fulfillment_orders (order_id) WHERE status <> 'cancelled'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_orders');
    }
};
