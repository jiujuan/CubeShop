<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 余额账户 / 余额流水 / 充值单（收银台方案 §4.1(2)(3)(4)）
 *
 * - user_balances：每用户一行，version 为乐观锁
 * - user_balance_logs：只增不改，记录 before/after
 * - balance_recharges：充值单，资金流仍走 payments（biz_type=recharge）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('balance', 12, 2)->default(0)->comment('可用余额');
            $table->decimal('frozen', 12, 2)->default(0)->comment('冻结（退款/提现中预留）');
            $table->decimal('total_recharge', 12, 2)->default(0)->comment('累计充值');
            $table->decimal('total_consume', 12, 2)->default(0)->comment('累计消费');
            $table->integer('version')->default(0)->comment('乐观锁');
            $table->timestamps();
        });

        Schema::create('user_balance_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 32)->comment('recharge/consume/refund/admin_adjust');
            $table->decimal('amount', 12, 2)->comment('正=入账，负=出账');
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('related_type', 32)->nullable()->comment('order/payment/recharge/refund');
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('后台调整时的管理员');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::create('balance_recharges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('recharge_no', 64)->unique()->comment('RC20260916000001');
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount', 12, 2)->comment('充值本金');
            $table->decimal('gift_amount', 12, 2)->default(0)->comment('赠送金额');
            $table->string('channel', 32)->comment('wechat/alipay/offline/mock');
            $table->string('status', 32)->comment('pending/reviewing/success/failed/closed');
            $table->unsignedBigInteger('payment_id')->nullable()->comment('关联支付单');
            // 线下转账专用
            $table->string('payer_name', 64)->nullable();
            $table->string('payer_account', 128)->nullable();
            $table->string('transfer_no', 128)->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->string('voucher_url', 255)->nullable();
            $table->string('review_remark', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable()->comment('入账时间');
            $table->timestamp('expired_at')->nullable()->comment('支付超时时间');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_recharges');
        Schema::dropIfExists('user_balance_logs');
        Schema::dropIfExists('user_balances');
    }
};
