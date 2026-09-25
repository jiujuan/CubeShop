<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 支付渠道日终对账（A7-支付渠道对账）
 *
 * 两表：
 * - payment_reconciliation_runs：每个渠道每个对账日一条「批次头」，记录本地/渠道两侧
 *   的笔数、金额汇总与对账结论（done / partial / failed），便于出日终报告。
 * - payment_reconciliation_diffs：差异清单兼工单。一条差异 = 一笔对不上的账目，
 *   由运营 resolve（处置/补单）或 ignore（认下）后关闭。
 *
 * 差异本身只记录，不自动改支付单；处置是人工动作，走后台并写操作日志。
 *
 * 幂等：同一 (reconcile_date, channel, payment_no|'', channel_trade_no|'', diff_type)
 * 同时只保留一条 pending（部分唯一索引，PG 与 SQLite 均支持），因此每日重跑不会把
 * 同一差异重复开单；已 resolved/ignored 的差异不会被覆盖重开。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->date('reconcile_date');
            $table->string('channel', 32);
            // running / done（无差异）/ partial（有差异）/ failed（渠道账单拉取失败）
            $table->string('status', 20)->default('running');
            $table->integer('local_count')->default(0);
            $table->integer('channel_count')->default(0);
            $table->integer('matched_count')->default(0);
            $table->integer('diff_count')->default(0);
            // 本地成功金额合计 vs 渠道账单金额合计（用于长短款总览，单位元）
            $table->decimal('local_amount', 14, 2)->default(0);
            $table->decimal('channel_amount', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['reconcile_date', 'channel'], 'pay_recon_run_unique');
            $table->index(['channel', 'status'], 'pay_recon_run_ch_status');
        });

        Schema::create('payment_reconciliation_diffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->date('reconcile_date');
            $table->string('channel', 32);
            // MISSING_LOCAL / MISSING_CHANNEL / AMOUNT_MISMATCH / DUPLICATE_CALLBACK / UNKNOWN
            $table->string('diff_type', 32);
            $table->string('payment_no', 64)->nullable();
            $table->string('channel_trade_no', 128)->nullable();
            $table->string('order_no', 32)->nullable();
            $table->decimal('local_amount', 14, 2)->nullable();
            $table->decimal('channel_amount', 14, 2)->nullable();
            $table->string('local_status', 32)->nullable();
            $table->string('channel_status', 32)->nullable();
            $table->text('detail')->nullable();
            // pending / processing / resolved / ignored
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('handle_remark', 500)->default('');
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('payment_reconciliation_runs')->cascadeOnDelete();
            $table->index(['channel', 'status'], 'pay_recon_diff_ch_status');
            $table->index(['reconcile_date', 'channel'], 'pay_recon_diff_date_ch');
            $table->index('status', 'pay_recon_diff_status');
        });

        DB::statement(
            'CREATE UNIQUE INDEX pay_recon_diff_pending_unique
             ON payment_reconciliation_diffs (reconcile_date, channel, COALESCE(payment_no, \'\'), COALESCE(channel_trade_no, \'\'), diff_type) WHERE status = \'pending\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_diffs');
        Schema::dropIfExists('payment_reconciliation_runs');
    }
};
