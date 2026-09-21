<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * T-042（E03）：快递公司字典种子
 *
 * `code` 为系统内部编码（发货录入用，`shippings.company_code` 的唯一合法口径）；
 * `carrier_codes` 是各渠道编码映射，本次只预填 **快递100**（`channel_code` 同源，保留做兼容）。
 *
 * 菜鸟奇门未逐行预填的原因：奇门回传的 `logisticsCode` 恰与平台码一致（SF/YTO/JD 等），
 * 而 {@see \App\Support\CarrierCode::fromChannel()} 的规则 3「平台码本身也算命中」已覆盖，
 * 不必冗余配置。若某家仓方实际回传值不同，在后台字典页补 `carrier_codes.cainiao` 即可。
 *
 * ⚠️ 新增快递公司务必同步 `carrier_codes` —— 否则轨迹查询只能靠「回落平台码」碰运气
 * （详见 docs/design/CubeShop_Logistics_Layering_and_CarrierCode_v1.0.md §4）。
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
                    'carrier_codes' => json_encode(
                        ['kuaidi100' => $row['channel_code']] + ($row['carrier_codes'] ?? []),
                        JSON_UNESCAPED_UNICODE,
                    ),
                    'sort' => $row['sort'],
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
