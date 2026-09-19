<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运单包裹明细（WMS 计划 P3 / Step 1）
 *
 * 背景：菜鸟回传的发货单可能**拆多包裹**（deliveryorder.confirm 的 packages 列表），
 * 而 `shippings` 主表只承载「主运单号」（与既有订单展示链路保持零变化）。
 * 全量包裹落本表：主表取 sort=0 的首包裹，其余留档供后台/客服查询。
 *
 * 不改 `shippings` 既有列——订单链路（P2-11 已接 public_id 的地方）不受影响。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipping_id')->comment('shippings.id');
            $table->string('tracking_no', 64)->comment('包裹运单号');
            $table->string('carrier_code', 32)->nullable()->comment('承运商编码（奇门 logisticsCode）');
            $table->string('carrier_name', 64)->nullable()->comment('承运商名称');
            $table->decimal('weight', 10, 3)->nullable()->comment('包裹重量(kg)');
            // ⚠️ $table->json() 而非 jsonb：SQLite（Pest 全量基线）无 jsonb，双库兼容
            $table->json('items')->nullable()->comment('包裹内商品明细 [{platform_sku_code,wms_sku_code,item_name,quantity}]');
            $table->unsignedInteger('sort')->default(0)->comment('包裹序号，0 为主包裹');
            $table->timestamps();

            $table->unique(['shipping_id', 'tracking_no'], 'uk_shipping_packages_shipping_tracking');
            $table->index('tracking_no', 'idx_shipping_packages_tracking');
            $table->foreign('shipping_id')->references('id')->on('shippings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_packages');
    }
};
