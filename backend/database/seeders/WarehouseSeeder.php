<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * 默认仓库种子（WMS 计划 P0 / Step 6）
 *
 * 预置 1 个默认仓，让「配置 WMS / SKU 映射」在没有任何仓库数据时也能立刻开始；
 * **不预置 WMS 配置**——避免把假凭证带进开发库（真实凭证由运营在后台录入）。
 *
 * 幂等：按 code 判断存在即跳过，可随 migrate:fresh 反复执行。
 */
class WarehouseSeeder extends Seeder
{
    /** 默认仓编码（P1 起订单回填 warehouse_id 时引用） */
    public const DEFAULT_CODE = 'WH_DEFAULT';

    public function run(): void
    {
        Warehouse::firstOrCreate(
            ['code' => self::DEFAULT_CODE],
            [
                'name' => '默认仓库',
                'contact_name' => null,
                'contact_phone' => null,
                'province' => null,
                'city' => null,
                'district' => null,
                'address' => null,
                'status' => 1,
            ],
        );
    }
}
