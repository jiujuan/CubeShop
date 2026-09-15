<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

/**
 * V1.1 F01/F02（T-015 / T-018）配置项种子
 *
 * - review.audit_mode：评价先审后显开关（默认关闭）
 * - review.sensitive_words：敏感词表（JSON 数组）
 * - review.summary_cache_ttl：商品评分汇总缓存秒数
 * - notify.mail_types：走邮件通道的通知类型（JSON 数组，默认仅支付成功）
 */
class ReviewNotifySeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            ['config_key' => 'review.audit_mode', 'config_value' => '0', 'description' => '评价先审后显：1 开启（新评价需审核通过才展示），0 关闭'],
            ['config_key' => 'review.sensitive_words', 'config_value' => '["违禁","刷单","代购"]', 'description' => '评价敏感词表（JSON 数组），命中则拒绝提交'],
            ['config_key' => 'review.summary_cache_ttl', 'config_value' => '300', 'description' => '商品评分汇总缓存时间（秒）'],
            ['config_key' => 'notify.mail_types', 'config_value' => '["order_paid"]', 'description' => '走邮件通道的通知类型（JSON 数组），站内信始终发送'],
        ];

        foreach ($configs as $config) {
            SystemConfig::updateOrCreate(
                ['config_key' => $config['config_key']],
                $config,
            );
        }
    }
}
