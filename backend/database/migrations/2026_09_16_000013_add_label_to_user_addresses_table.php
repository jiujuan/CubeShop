<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.1 E04 / T-028：地址增强（标签 / 使用频次）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->string('label', 16)->nullable()->after('detail_address')->comment('地址标签：家/公司/学校等');
            $table->unsignedInteger('used_count')->default(0)->after('label')->comment('下单使用次数');
            $table->timestamp('last_used_at')->nullable()->after('used_count')->comment('最近使用时间');
        });
    }

    public function down(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->dropColumn(['label', 'used_count', 'last_used_at']);
        });
    }
};
