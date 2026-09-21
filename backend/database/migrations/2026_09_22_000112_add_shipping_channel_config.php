<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册物流查询渠道配置项（V1.1 三期）
 *
 * `shipping.channel` 允许在后台切换轨迹查询渠道，**不必改 .env 重启**。
 * 取值：''（跟随 .env 的 SHIPPING_CHANNEL）/ kuaidi100 / mock / off（强制关闭）。
 *
 * ⚠️ 只登记配置项，**不含密钥**：key / customer 属凭证，仍走 .env
 * （与 PAY_SIGN_SECRET 同款处理，避免凭证入库后被备份/导出扩散）。
 *
 * 首次插入用 firstOrCreate 语义：后台已改过的值不被迁移覆盖。
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('system_configs')
            ->where('config_key', 'shipping.channel')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('system_configs')->insert([
            'config_key' => 'shipping.channel',
            'config_value' => '',
            'description' => '物流轨迹查询渠道：留空=跟随 .env；kuaidi100=快递100；mock=本地演示；off=关闭查询（密钥在 .env 配置，不在此处）',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_configs')
            ->where('config_key', 'shipping.channel')
            ->delete();
    }
};
