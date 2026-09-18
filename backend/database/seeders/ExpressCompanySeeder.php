<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * T-042（E03）：快递公司字典种子
 *
 * code 为系统内部编码（发货录入用）；channel_code 按快递100 通用编码预填，
 * 当前查询渠道（T-045）默认 NullChannel 未配置，若最终接入其他渠道需同步更新本表。
 */
class ExpressCompanySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'SF', 'name' => '顺丰速运', 'channel_code' => 'shunfeng', 'sort' => 1],
            ['code' => 'ZTO', 'name' => '中通快递', 'channel_code' => 'zhongtong', 'sort' => 2],
            ['code' => 'YTO', 'name' => '圆通速递', 'channel_code' => 'yuantong', 'sort' => 3],
            ['code' => 'YD', 'name' => '韵达快递', 'channel_code' => 'yunda', 'sort' => 4],
            ['code' => 'STO', 'name' => '申通快递', 'channel_code' => 'shentong', 'sort' => 5],
            ['code' => 'JD', 'name' => '京东物流', 'channel_code' => 'jd', 'sort' => 6],
            ['code' => 'EMS', 'name' => '中国邮政 EMS', 'channel_code' => 'ems', 'sort' => 7],
            ['code' => 'JT', 'name' => '极兔速递', 'channel_code' => 'jtexpress', 'sort' => 8],
        ];

        foreach ($rows as $row) {
            DB::table('express_companies')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'channel_code' => $row['channel_code'],
                    'sort' => $row['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
