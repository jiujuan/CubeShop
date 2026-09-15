<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments 扩展（收银台方案 §4.2）
 *
 * - 新增 biz_type / biz_no：支持「订单支付」与「余额充值」两类业务共用一张支付单表
 * - 新增线下转账字段：凭证、付款人、流水号、核账信息
 * - order_id / order_no 改可空：充值单没有订单
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'biz_type')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('biz_type', 32)->default('order')->comment('order/recharge');
                $table->string('biz_no', 64)->nullable()->comment('业务单号（订单号/充值单号）');
                $table->string('payer_name', 64)->nullable()->comment('线下转账：付款人姓名');
                $table->string('payer_account', 128)->nullable()->comment('线下转账：付款账号');
                $table->string('transfer_no', 128)->nullable()->comment('线下转账：银行流水号');
                $table->timestamp('transferred_at')->nullable()->comment('线下转账：转账时间');
                $table->string('voucher_url', 255)->nullable()->comment('线下转账：凭证图片');
                $table->string('review_remark', 255)->nullable()->comment('核账备注/驳回原因');
                $table->unsignedBigInteger('reviewed_by')->nullable()->comment('核账人');
                $table->timestamp('reviewed_at')->nullable()->comment('核账时间');

                $table->index(['biz_type', 'biz_no']);
            });
        }

        $this->makeOrderColumnsNullable();
    }

    /**
     * order_id / order_no 改可空
     *
     * SQLite 不支持直接 MODIFY，Laravel 会重建表；为兼容 SQLite 与 PostgreSQL，
     * 先判断当前可空性，避免重复执行报错。
     */
    private function makeOrderColumnsNullable(): void
    {
        $columns = collect(Schema::getColumns('payments'))->keyBy('name');

        $needOrderId = ($columns['order_id']['nullable'] ?? false) === false;
        $needOrderNo = ($columns['order_no']['nullable'] ?? false) === false;

        if (! $needOrderId && ! $needOrderNo) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) use ($needOrderId, $needOrderNo) {
            if ($needOrderId) {
                $table->unsignedBigInteger('order_id')->nullable()->change();
            }
            if ($needOrderNo) {
                $table->string('order_no', 32)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['biz_type', 'biz_no']);
            $table->dropColumn([
                'biz_type', 'biz_no', 'payer_name', 'payer_account', 'transfer_no',
                'transferred_at', 'voucher_url', 'review_remark', 'reviewed_by', 'reviewed_at',
            ]);
        });
    }
};
