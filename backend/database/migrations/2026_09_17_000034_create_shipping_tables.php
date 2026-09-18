<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-042（E03/F07）：物流四表 + 订单冗余双号
 *
 * 字段依据 docs/design/v1.1/CubeShop_V1.1_Backend_Design.md §1.2（物流）/ §1.5（快递字典、运费模板）。
 * JSON 字段沿用项目既有 `jsonb()`（SQLite 下由框架映射为 text）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 发货记录（一单一发货，重复发货在业务层拒绝）
        Schema::create('shippings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->comment('订单');
            $table->string('company_code', 20)->comment('快递公司编码（字典）');
            $table->string('company_name', 50)->comment('快递公司名称快照');
            $table->string('tracking_no', 50)->comment('运单号');
            $table->string('trace_status', 20)->default('pending')->comment('pending / in_transit / delivered / failed');
            $table->timestamp('shipped_at')->nullable()->comment('发货时间');
            $table->timestamp('delivered_at')->nullable()->comment('签收时间');
            $table->timestamps();

            $table->index('order_id');
            $table->index('trace_status');
            $table->unique(['company_code', 'tracking_no'])->comment('同一快递公司的运单号唯一');
        });

        // 物流轨迹
        Schema::create('shipping_traces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shipping_id')->comment('发货记录');
            $table->string('context', 500)->comment('轨迹描述');
            $table->timestamp('occurred_at')->comment('轨迹发生时间');
            $table->jsonb('raw')->nullable()->comment('第三方原始报文');
            $table->timestamps();

            $table->index(['shipping_id', 'occurred_at']);
        });

        // 快递公司字典
        Schema::create('express_companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique()->comment('公司编码，如 SF');
            $table->string('name', 50)->comment('公司名称');
            $table->string('channel_code', 30)->nullable()->comment('第三方查询渠道编码，未定留空');
            $table->integer('sort')->default(0);
            $table->smallInteger('status')->default(1)->comment('1 启用 / 0 停用');
            $table->timestamps();
        });

        // 运费模板（可选启用，本版仅建表）
        Schema::create('freight_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->comment('模板名');
            $table->string('mode', 20)->comment('fixed / weight / region');
            $table->jsonb('rules')->comment('规则数组');
            $table->smallInteger('status')->default(1);
            $table->timestamps();
        });

        // 订单冗余双号（列表与导出直接用，避免每次 join）
        Schema::table('orders', function (Blueprint $table) {
            $table->string('express_company', 50)->nullable()->comment('快递公司名称快照')->after('paid_at');
            $table->string('tracking_no', 50)->nullable()->comment('运单号')->after('express_company');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['express_company', 'tracking_no']);
        });
        Schema::dropIfExists('freight_templates');
        Schema::dropIfExists('express_companies');
        Schema::dropIfExists('shipping_traces');
        Schema::dropIfExists('shippings');
    }
};
