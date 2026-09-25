<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 对账平台维度（A7 增强）：订单来源端打标
 *
 * - payments.platform：该支付单来自哪个客户端（web / h5 / miniprogram）。
 *   存量数据回填 web；写入路径由 PaymentService 在创建支付单时按请求头
 *   X-Client-Platform 打标（白名单外一律回退 web，控制台/无请求上下文亦为 web）。
 * - payment_reconciliation_diffs.platform：差异产生时从本地支付单反查冗余落库，
 *   便于按平台聚合看板；渠道侧独有差异（MISSING_CHANNEL，本地无单可挂）为 null，
 *   只会出现在「全部平台」口径里。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('platform', 20)->default('web');
            $table->index('platform', 'payments_platform_index');
        });

        Schema::table('payment_reconciliation_diffs', function (Blueprint $table) {
            $table->string('platform', 20)->nullable();
            $table->index('platform', 'pay_recon_diff_platform');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_platform_index');
            $table->dropColumn('platform');
        });

        Schema::table('payment_reconciliation_diffs', function (Blueprint $table) {
            $table->dropIndex('pay_recon_diff_platform');
            $table->dropColumn('platform');
        });
    }
};
