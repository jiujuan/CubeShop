<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 评价隐藏（后台可隐藏某条用户评价，隐藏后前台不展示且不计入评分汇总）
 *
 * 默认 false（展示）；隐藏只影响前台展示，评价数据保留，可随时恢复。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('status');
            $table->index('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['is_hidden']);
            $table->dropColumn('is_hidden');
        });
    }
};
