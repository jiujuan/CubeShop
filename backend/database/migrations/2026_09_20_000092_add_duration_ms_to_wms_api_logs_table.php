<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wms_api_logs 补耗时列（WMS 计划 P7 / 联调发现项 D-P7-3）
 *
 * 背景：`WmsApiLogService::record()` 自 P2 起就接收 `$durationMs` 参数，
 * 但表里**根本没有这一列**——`WmsApiLog::create()` 因 `$fillable` 不含该键
 * 而静默丢弃，等于「每次调用耗时」这条可观测数据 P0～P6 一直没落库，
 * 联调报告里「接口耗时 / 性能基线」无从取数。
 *
 * 本迁移补上 `duration_ms`（可空，历史行保持 null），与 P7 同步新增的
 * 入站回调耗时（D-P7-1）共用同一列，出站/入站口径统一。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_api_logs')) {
            return;
        }

        Schema::table('wms_api_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('wms_api_logs', 'duration_ms')) {
                // 毫秒；单次 HTTP 调用上限远小于 int，留 unsigned 即可
                $table->unsignedInteger('duration_ms')->nullable()->after('http_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_api_logs')) {
            return;
        }

        Schema::table('wms_api_logs', function (Blueprint $table) {
            if (Schema::hasColumn('wms_api_logs', 'duration_ms')) {
                $table->dropColumn('duration_ms');
            }
        });
    }
};
