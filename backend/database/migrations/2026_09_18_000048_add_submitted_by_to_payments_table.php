<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-14：线下支付「审核人不得为提交人」的隔离校验需要可靠的提交人身份。
 *
 * 历史实现在 PaymentService::review() 里直接比对 `$adminId === (int) $payment->user_id`，
 * 但 `user_id` 是买家（users 域），而审核人来自 sys_user（管理员域）——两个不同身份域的主键
 * 单纯按数值相等比较既语义错误，又会在「买家 id 与某管理员 id 恰好相等」时误伤（测试环境
 * 两表均从 1 起号即触发）。
 *
 * 这里新增 `submitted_by`（提交人主键）+ `submitted_by_type`（身份域：user / sys_user），
 * 由 createPayment 在生成支付单时按真实提交人写入。review() 仅在「提交人与审核人同属管理员域」
 * 时才做自审隔离，跨域（买家提交、管理员审核）天然不触发，从而消除误伤且保留四人眼原则。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by')->nullable()->comment('提交人主键（买家或管理员）');
            $table->string('submitted_by_type', 16)->nullable()->comment('提交人身份域：user / sys_user');
            $table->index(['submitted_by', 'submitted_by_type']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['submitted_by', 'submitted_by_type']);
            $table->dropColumn(['submitted_by', 'submitted_by_type']);
        });
    }
};
