<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 系统配置
 * 对应设计文档：2.1.4 system_configs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('config_key', 128)->unique()->comment('配置键');
            $table->text('config_value')->nullable()->comment('配置值');
            $table->string('description', 255)->nullable()->comment('说明');
            $table->timestamps();
        });

        // 预置配置（见设计文档 2.1.4）
        DB::table('system_configs')->insert([
            ['config_key' => 'order.timeout_minutes', 'config_value' => '30', 'description' => '订单支付超时时间（分钟）', 'created_at' => now(), 'updated_at' => now()],
            ['config_key' => 'order.freight_default', 'config_value' => '10.00', 'description' => '默认运费（元）', 'created_at' => now(), 'updated_at' => now()],
            ['config_key' => 'inventory.warning_threshold', 'config_value' => '10', 'description' => '库存预警阈值', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_configs');
    }
};
