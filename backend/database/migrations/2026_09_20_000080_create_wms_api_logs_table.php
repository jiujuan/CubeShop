<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS 接口调用日志（WMS 计划 P0 / README §5-5，P2/P3 复用）
 *
 * 所有出入站报文在此留痕（含连通性测试），是「可观测 / 可重放」的基础：
 * - direction：outbound 平台→WMS、inbound WMS→平台（回调）
 * - provider / api_name / biz_no / request_id：定位一次调用及其业务单据
 * - request_body / response_body：脱敏后的原始报文（Laravel json()，PG=jsonb）
 * - http_status / success / error_msg：快查失败原因
 *
 * P0 先建表与记录能力，P2/P3 直接复用，不再改表结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 16);                 // outbound / inbound
            $table->string('provider', 16);                  // cainiao / jd_cloud / mock
            $table->string('api_name', 64);                  // queryInventory / createOutbound / callback.ship ...
            // 平台侧请求标识（用于跨系统串联同一次调用）
            $table->string('request_id', 64)->nullable();
            // 业务单号（订单号 / 履约单号 / 退货单号），无则 null
            $table->string('biz_no', 64)->nullable();
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error_msg')->nullable();
            // 仅 created_at（日志不可变，无需 updated_at）
            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'api_name']);
            $table->index('biz_no');
            $table->index('success');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_api_logs');
    }
};
