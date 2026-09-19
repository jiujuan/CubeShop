<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

/**
 * 站点基础信息配置（P-SiteConfig）
 *
 * ⚠️ 与其他配置 Seeder 不同，此处用 firstOrCreate：站点名称/logo 属运营个性化数据，
 * 重复播种时不得覆盖后台已修改的值；新增 key 仍会被补上。
 */
class SiteConfigSeeder extends Seeder
{
    /** @var list<array{config_key: string, config_value: string, description: string}> */
    public const DEFAULTS = [
        ['config_key' => 'site.name', 'config_value' => 'CubeShop', 'description' => '电商站点名称（顶栏/登录页/页脚/浏览器标题）'],
        ['config_key' => 'site.logo', 'config_value' => '', 'description' => '站点大 logo 图片地址（桌面端顶栏；留空则回落到内置图标）'],
        ['config_key' => 'site.logo_small', 'config_value' => '', 'description' => '站点小 logo 图片地址（移动端顶栏；留空则回落到大 logo / 内置图标）'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $config) {
            SystemConfig::firstOrCreate(
                ['config_key' => $config['config_key']],
                ['config_value' => $config['config_value'], 'description' => $config['description']],
            );
        }
    }
}
