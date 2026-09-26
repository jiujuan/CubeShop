<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

/**
 * 退款策略配置（refund.*，退款后台 #6）：预置默认值，后台「退款策略」页可改。
 * 仅退款路径行为完全不变的前提：auto_approve_amount 默认 '0.00'（不启用自动同意）。
 */
class RefundConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            ['config_key' => 'refund.auto_approve_amount', 'config_value' => '0.00', 'description' => '退款自动同意阈值（元）：退款金额不超该值时买家申请后系统自动审核通过，0 表示不启用'],
            ['config_key' => 'refund.max_retry', 'config_value' => '3', 'description' => '渠道退款失败自动重试上限（次），达到上限仍失败转人工处理'],
            ['config_key' => 'refund.dispute_sla_hours', 'config_value' => '48', 'description' => '退款纠纷处理 SLA（小时），超时在后台纠纷列表标记催办'],
            ['config_key' => 'refund.return_address_template', 'config_value' => '', 'description' => '退货地址模板（买家退货退款时展示，支持换行）'],
        ];

        foreach ($configs as $config) {
            SystemConfig::updateOrCreate(
                ['config_key' => $config['config_key']],
                ['config_value' => $config['config_value'], 'description' => $config['description']],
            );
        }
    }
}
