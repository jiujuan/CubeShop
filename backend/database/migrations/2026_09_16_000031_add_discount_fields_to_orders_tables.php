<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-031（F06）：订单/订单明细新增优惠字段（金额链路预留）
 *
 * - 新字段一律可空或默认 0，**不改动 V1.0 既有金额计算路径**，旧数据与旧用例不受影响。
 * - `amount_details` 为优惠分摊快照（含版本号），历史订单为 NULL。
 *
 * 字段依据 docs/design/v1.1/CubeShop_V1.1_Backend_Design.md §1.4（存量表改造）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id')->nullable()->after('pay_amount')->comment('使用的券模板');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('coupon_id')->comment('券优惠额');
            $table->decimal('promotion_discount', 12, 2)->default(0)->after('discount_amount')->comment('满减优惠额');
            $table->jsonb('amount_details')->nullable()->after('promotion_discount')->comment('金额分摊快照（含版本号）');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('coupon_share', 12, 2)->default(0)->after('total_amount')->comment('该行分摊的券优惠');
            $table->decimal('promotion_share', 12, 2)->default(0)->after('coupon_share')->comment('该行分摊的满减优惠');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['coupon_share', 'promotion_share']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_id', 'discount_amount', 'promotion_discount', 'amount_details']);
        });
    }
};
