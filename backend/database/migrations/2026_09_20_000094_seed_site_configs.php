<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 站点基础信息配置初始值（P-SiteConfig）
 *
 * 只写不存在的 key，不覆盖运营已调整过的值（老库升级场景）；幂等可重复执行。
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'site.name' => 'CubeShop',
        'site.logo' => '',
        'site.logo_small' => '',
    ];

    private const DESCRIPTIONS = [
        'site.name' => '电商站点名称（顶栏/登录页/页脚/浏览器标题）',
        'site.logo' => '站点大 logo 图片地址（桌面端顶栏；留空则回落到内置图标）',
        'site.logo_small' => '站点小 logo 图片地址（移动端顶栏；留空则回落到大 logo / 内置图标）',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $now = now();
        foreach (self::DEFAULTS as $key => $value) {
            if (DB::table('system_configs')->where('config_key', $key)->exists()) {
                continue;
            }
            DB::table('system_configs')->insert([
                'config_key' => $key,
                'config_value' => $value,
                'description' => self::DESCRIPTIONS[$key] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->whereIn('config_key', array_keys(self::DEFAULTS))->delete();
    }
};
