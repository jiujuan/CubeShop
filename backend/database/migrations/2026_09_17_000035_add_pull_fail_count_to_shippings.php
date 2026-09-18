<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.1 T-045：shippings 增加连续拉取失败计数
 *
 * 轨迹拉取 Job 每次失败 +1，成功清零；连续达到上限（默认 5）置 trace_status=failed。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shippings', function (Blueprint $table) {
            $table->unsignedInteger('pull_fail_count')->default(0)->after('trace_status')->comment('连续拉取失败次数');
            $table->string('last_fail_message', 200)->nullable()->after('pull_fail_count')->comment('最近一次失败原因（达到上限标记 failed 时固化）');
        });
    }

    public function down(): void
    {
        Schema::table('shippings', function (Blueprint $table) {
            $table->dropColumn('pull_fail_count');
        });
    }
};
