<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 注册电子面单申请渠道配置项（V1.2）
 *
 * `waybill.channel` 允许在后台切换「发货时是否自动出电子面单」，
 * **不必改 .env 重启**，与轨迹查询渠道 `shipping.channel` 完全对称。
 * 取值：''（跟随 .env 的 WAYBILL_CHANNEL）/ kuaidi100 / mock / off（强制手动录入，不出面单）。
 *
 * ⚠️ 只登记配置项，**不含密钥**：key / customer 属凭证，仍走 .env
 * （与 PAY_SIGN_SECRET、shipping.channel 同款处理，避免凭证入库后被备份/导出扩散）。
 *
 * 首次插入用存在性判断：后台已改过的值不被迁移覆盖。
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('system_configs')
            ->where('config_key', 'waybill.channel')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('system_configs')->insert([
            'config_key' => 'waybill.channel',
            'config_value' => '',
            'description' => '电子面单申请渠道：留空=跟随 .env；kuaidi100=快递100（需签约）；mock=本地演示（不出真实请求）；off=强制手动录入运单号（密钥在 .env 配置，不在此处）',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_configs')
            ->where('config_key', 'waybill.channel')
            ->delete();
    }
};
