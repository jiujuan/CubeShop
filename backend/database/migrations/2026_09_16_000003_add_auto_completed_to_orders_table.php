<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * orders 增加「自动确认收货」标记（V1.1 E02-A / T-003）
 *
 * 便于报表区分与售后争议排查：该订单的 completed 是由系统自动流转而来。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('auto_completed')->default(false)->comment('是否系统自动确认收货');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('auto_completed');
        });
    }
};
