<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-036 [BE] 退款金额分摊快照
 *
 * 退款单增加 `refund_details`（JSON）：下单时生成的 `orders.amount_details` 是优惠后
 * 每行实付的唯一可信来源。申请退款时把「每行实付 = 价×量 − coupon_share − promotion_share」
 * 与订单优惠构成固化进退款单，作为「可退上限 / 退款金额校验 / 退款不变量审计」的不可变依据，
 * 避免退款时二次重算口径漂移（与 T-034 同一套分摊规则）。
 *
 * 双库兼容：SQLite 用 JSON 文本，PG 用 JSONB（Schema::addColumn 的 json 类型自动择库）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->json('refund_details')->nullable()->after('amount')
                ->comment('退款时固化的订单优惠构成与每行实付快照（来自 orders.amount_details）');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('refund_details');
        });
    }
};
