<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

/**
 * V1.1 E05-B（T-027）账号安全配置项种子
 *
 * 密码强度规则参数化，便于不改代码调整策略。
 */
class AuthSecuritySeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            ['config_key' => 'auth.password_min_length', 'config_value' => '8', 'description' => '密码最小长度'],
            ['config_key' => 'auth.password_max_length', 'config_value' => '32', 'description' => '密码最大长度'],
            ['config_key' => 'auth.password_require_mixed', 'config_value' => '1', 'description' => '密码是否必须同时包含字母与数字：1 是，0 否'],
        ];

        foreach ($configs as $config) {
            SystemConfig::updateOrCreate(
                ['config_key' => $config['config_key']],
                $config,
            );
        }
    }
}
