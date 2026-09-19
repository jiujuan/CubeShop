<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退货入库单主表（WMS 计划 P4 / 设计文档 §4.1、D3 决策）
 *
 * 一个退货退款单（refunds.type=return_refund）一张入库单：UNIQUE(refund_no)。
 * after_sale_no 等价 refund_no（README D-P4-2，不新建 after_sale 表）。
 *
 * 状态机（§2.4 / Step 2）：
 * created ──► pending_push ──► pushing ──┬─► pushed ──► receiving ──► received ──► completed
 *    │             │              │      └─► push_failed ──┐
 *    └─────────────┴──────────────┴────────────────────────┴─► cancelled
 *                                              exception ─────┘（补映射后回 pending_push）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_inbound_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_id')->comment('退货退款单（D3：after_sale 即 refund）');
            $table->string('refund_no', 32)->comment('售后单号 = 退款单号（D-P4-2）');
            $table->unsignedBigInteger('order_id')->nullable()->comment('原订单');
            $table->string('order_no', 32)->nullable()->comment('订单号冗余（列表筛选免联表）');
            $table->string('inbound_no', 32)->comment('平台退货入库单号 RI+日期+随机段');
            $table->unsignedBigInteger('warehouse_id')->comment('退货入库仓');
            $table->string('provider', 16)->nullable()->comment('服务商快照 cainiao/mock');
            $table->string('status', 32)
                ->comment('created/pending_push/pushing/pushed/receiving/received/completed/cancelled/exception/push_failed');

            // ---- WMS 回传 ----
            $table->string('wms_inbound_no', 64)->nullable()->comment('WMS 侧入库单号');

            // ---- 推送状态 ----
            $table->string('push_request_id', 64)->nullable()->comment('推送幂等键（Job 生成，重试沿用）');
            $table->unsignedInteger('push_times')->default(0)->comment('已推送次数');
            $table->timestamp('last_push_at')->nullable();
            $table->text('last_push_error')->nullable()->comment('最近一次推送失败原因');

            // ---- 时间与异常 ----
            $table->timestamp('received_at')->nullable()->comment('WMS 收货时间（回传/手工兜底）');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('exception_reason', 255)->nullable()->comment('建单/收货异常原因（人工介入线索）');
            $table->string('return_reason', 255)->nullable()->comment('退货原因（推送给 WMS）');

            $table->json('extend')->nullable();
            $table->timestamps();

            $table->unique('inbound_no');
            $table->index('refund_no');
            $table->index(['warehouse_id', 'status']);
            $table->index('order_no');

            $table->foreign('refund_id')->references('id')->on('refunds')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });

        // 一个退货退款单同一时刻至多一张活跃入库单（已取消的不占名额，允许重建）。
        // PG 与 SQLite 均支持部分唯一索引。
        DB::statement(
            "CREATE UNIQUE INDEX return_inbound_orders_active_refund_unique
             ON return_inbound_orders (refund_id) WHERE status <> 'cancelled'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('return_inbound_orders');
    }
};
