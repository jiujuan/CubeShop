<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付记录 / 支付日志
 * 对应设计文档：2.7 payments、payment_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payment_no', 64)->unique()->comment('支付单号');
            $table->unsignedBigInteger('order_id')->comment('订单');
            $table->string('order_no', 32)->comment('冗余订单号');
            $table->unsignedBigInteger('user_id')->comment('支付用户');
            $table->string('channel', 32)->comment('wechat/alipay');
            $table->decimal('amount', 12, 2)->comment('支付金额');
            $table->string('status', 32)->comment('pending/success/failed/closed');
            $table->string('channel_trade_no', 128)->nullable()->comment('渠道交易号');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->index('order_id');
        });

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('payment_no', 64)->nullable();
            $table->string('event', 64)->comment('create/callback/notify');
            $table->jsonb('request_data')->nullable();
            $table->jsonb('response_data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
        Schema::dropIfExists('payments');
    }
};
