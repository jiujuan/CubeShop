<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 支付相关业务开关初始值（收银台方案 §4.3）
 *
 * 只写不存在的 key，不覆盖运营已调整过的值；幂等可重复执行。
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'payment.default_channel' => 'wechat',
        'payment.balance_enabled' => '1',
        'payment.offline_enabled' => '1',
        'payment.offline_receipt' => '{"bank_name":"","account_name":"","account_no":"","qrcode_url":""}',
        'payment.query_max_attempts' => '10',
        'payment.result_poll_seconds' => '2',
        'payment.recharge_enabled' => '1',
        'payment.recharge_amounts' => '50,100,200,500',
        'payment.recharge_min_amount' => '10.00',
        'payment.recharge_max_single' => '5000.00',
        'payment.recharge_max_daily' => '20000.00',
        'payment.recharge_gift_rules' => '[]',
        'payment.recharge_timeout_minutes' => '30',
    ];

    private const DESCRIPTIONS = [
        'payment.default_channel' => '收银台默认选中渠道',
        'payment.balance_enabled' => '余额支付总开关',
        'payment.offline_enabled' => '线下转账总开关',
        'payment.offline_receipt' => '线下收款账户（单账户 JSON）',
        'payment.query_max_attempts' => '主动查单最大次数',
        'payment.result_poll_seconds' => '结果页轮询间隔（秒）',
        'payment.recharge_enabled' => '余额充值总开关',
        'payment.recharge_amounts' => '充值页固定面额（逗号分隔）',
        'payment.recharge_min_amount' => '自定义充值最小金额',
        'payment.recharge_max_single' => '单笔充值上限',
        'payment.recharge_max_daily' => '单日累计充值上限',
        'payment.recharge_gift_rules' => '充值赠送规则 JSON：[{amount:100,gift:10}]',
        'payment.recharge_timeout_minutes' => '充值单支付超时（分钟）',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $now = now();
        foreach (self::DEFAULTS as $key => $value) {
            $exists = DB::table('system_configs')->where('config_key', $key)->exists();
            if ($exists) {
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
