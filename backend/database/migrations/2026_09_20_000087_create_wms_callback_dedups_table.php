<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS 回调业务幂等去重表（WMS 计划 P3 / Step 1）
 *
 * 四元组 (provider, biz_no, msg_type, status_key) 唯一：
 * - 菜鸟可能对同一事件重复推送（网络抖动重试、对方补偿机制）；
 * - 业务幂等第一层是 `fulfillment_orders` 状态机（非法流转拒收），
 *   本表是第二层「已处理登记」，避免对已终态单据反复打审计日志。
 *
 * ⚠️ `status_key` 必须 NOT NULL DEFAULT ''：唯一索引对 NULL 不生效
 *    （PG / MySQL / SQLite 均是 NULL != NULL），用空串表达「无状态语义」的消息。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_callback_dedups', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->comment('cainiao / jd_cloud');
            $table->string('biz_no', 64)->comment('业务单号（outbound_no）');
            $table->string('msg_type', 32)->comment('消息类型（confirm / status）');
            $table->string('status_key', 32)->default('')->comment('状态键（已出库/拣货中…），无语义用空串');
            $table->string('note', 255)->nullable()->comment('备注（首收时间之外的补充信息）');
            $table->timestamp('received_at')->comment('首收时间');
            $table->timestamps();

            $table->unique(['provider', 'biz_no', 'msg_type', 'status_key'], 'uk_wms_callback_dedups_quad');
            $table->index('received_at', 'idx_wms_callback_dedups_received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_callback_dedups');
    }
};
