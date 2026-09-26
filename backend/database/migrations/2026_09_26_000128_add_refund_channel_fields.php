<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 退款接入第三方渠道（支付宝/微信/余额）所需字段
 *
 * - channel / payment_no：原路退回与查单所需（取订单成功支付单的渠道）
 * - out_refund_no：我方退款单号（幂等键），首次生成持久化，重试复用
 * - channel_refund_no：渠道退款单号（支付宝 trade_no / 微信 refund_id）
 * - refund_status：渠道侧状态快照（SUCCESS/PROCESSING/CLOSED/ABNORMAL），异步用
 * - channel_raw：最近一次渠道响应原文，用于排查/对账
 * - failed_reason：渠道退款失败原因，便于后台重试展示
 * - refunded_at：渠道确认退款成功时间
 * - retry_count：已重试次数，配合 RefundService::MAX_RETRY 上限
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('channel', 16)->nullable()->comment('渠道 wechat/alipay/balance/offline');
            $table->string('payment_no', 64)->nullable()->comment('原支付单号（渠道 out_trade_no）');
            $table->string('out_refund_no', 64)->nullable()->comment('我方退款单号（幂等键）');
            $table->string('channel_refund_no', 64)->nullable()->comment('渠道退款单号');
            $table->string('refund_status', 32)->nullable()->comment('渠道侧状态快照');
            $table->json('channel_raw')->nullable()->comment('最近一次渠道响应原文');
            $table->string('failed_reason', 255)->nullable()->comment('渠道退款失败原因');
            $table->timestamp('refunded_at')->nullable()->comment('渠道确认退款成功时间');
            $table->unsignedInteger('retry_count')->default(0)->comment('已重试次数');

            $table->unique('out_refund_no', 'refunds_out_refund_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique('refunds_out_refund_no_unique');
            $table->dropColumn([
                'channel',
                'payment_no',
                'out_refund_no',
                'channel_refund_no',
                'refund_status',
                'channel_raw',
                'failed_reason',
                'refunded_at',
                'retry_count',
            ]);
        });
    }
};
