<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运费升级 Stage 2（T-053）：
 * - products.freight_template_id：商品绑定运费模板（null = 走全局默认规则，行为与旧版一致）；
 * - system_configs 预置 order.freight_template_id：全局默认模板（空 = 旧「固定运费 + 满额包邮」口径）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('freight_template_id')->nullable()->after('weight')->comment('绑定运费模板，null=全局默认');
            $table->foreign('freight_template_id')
                ->references('id')->on('freight_templates')
                ->nullOnDelete();
        });

        // 幂等预置全局默认模板配置（空 = 完全旧行为；不要改成有默认模板，保持平滑升级）
        DB::table('system_configs')->updateOrInsert(
            ['config_key' => 'order.freight_template_id'],
            ['config_value' => ''],
        );
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['freight_template_id']);
            $table->dropColumn('freight_template_id');
        });

        DB::table('system_configs')->where('config_key', 'order.freight_template_id')->delete();
    }
};
