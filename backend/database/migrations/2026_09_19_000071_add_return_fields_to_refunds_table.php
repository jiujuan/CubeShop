<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款表扩展「退货退款」能力（WMS 退货闭环基础，对应 WMS 计划 P4 / D3）
 *
 * 仅扩展现有 refunds 表，不新建 after_sale / return_inbound_orders（后者为后续真正接入菜鸟时再建）。
 * 本迁移让平台先具备「退货退款」领域模型，使 admin 可处理退货、web 可申请退货，
 * 待 WMS 接入时把「确认收货」改为由菜鸟回传触发即可，无需改字段结构。
 *
 * 字段命名避免与既有 refund_details（金额快照）混淆，新增退货相关字段统一以 return_ 前缀。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // 退款类型：refund 仅退款 / return_refund 退货退款（默认仅退款，向后兼容）
            $table->string('type', 16)->default('refund')->after('refund_no');
            // 退货收货仓库（WMS 阶段回填；本轮可空）
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('order_id');
            // 退货物流
            $table->string('return_tracking_no', 64)->nullable()->after('admin_remark');
            $table->string('return_express_company', 64)->nullable()->after('return_tracking_no');
            // 退货状态：null=仅退款；waiting_return(待退货)/shipping(退货中)/received(已收货)/exception(异常)
            $table->string('return_status', 16)->nullable()->after('return_express_company');
            // 申请时填写的应退明细 [{sku_id, product_title, sku_specs, quantity}]
            $table->json('return_details')->nullable()->after('return_status');
            // 后台确认收货时填写的实收明细 [{sku_id, quantity, condition:good|defective}]
            $table->json('return_received_details')->nullable()->after('return_details');
            $table->timestamp('return_received_at')->nullable()->after('return_received_details');
            // 实收差异 / 异常原因（如少件、残次、应退不符）
            $table->text('return_exception_reason')->nullable()->after('return_received_at');

            $table->index(['type', 'return_status'], 'refunds_type_return_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex('refunds_type_return_status_index');
            $table->dropColumn([
                'type',
                'warehouse_id',
                'return_tracking_no',
                'return_express_company',
                'return_status',
                'return_details',
                'return_received_details',
                'return_received_at',
                'return_exception_reason',
            ]);
        });
    }
};
