<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * P4 种子数据：订单相关系统配置（数据库设计 2.1.4 预置配置示例）
 */
class OrderConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            ['config_key' => 'order.timeout_minutes', 'config_value' => '30', 'description' => '订单支付超时时间（分钟），超时自动取消'],
            ['config_key' => 'order.freight_default', 'config_value' => '10.00', 'description' => '默认运费（元）'],
            ['config_key' => 'order.free_shipping_threshold', 'config_value' => '99.00', 'description' => '满额免运费阈值（元），0 表示不启用'],
            ['config_key' => 'inventory.warning_threshold', 'config_value' => '10', 'description' => '库存预警阈值（件），可用库存低于等于该值时预警'],
        ];

        foreach ($configs as $config) {
            \App\Models\SystemConfig::updateOrCreate(
                ['config_key' => $config['config_key']],
                ['config_value' => $config['config_value'], 'description' => $config['description']],
            );
        }
    }
}
