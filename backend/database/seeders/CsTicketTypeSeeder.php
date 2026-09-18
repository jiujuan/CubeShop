<?php

namespace Database\Seeders;

use App\Models\CsTicketType;
use Illuminate\Database\Seeder;

/**
 * CS-102：默认工单类型（设计文档 §3.3）
 *
 * 幂等：按 code 判重，可重复执行。
 */
class CsTicketTypeSeeder extends Seeder
{
    /** @var array<int, array{name: string, code: string, require_order: bool, sort: int}> */
    public const TYPES = [
        ['name' => '售前咨询', 'code' => 'pre_sale', 'require_order' => false, 'sort' => 10],
        ['name' => '物流问题', 'code' => 'logistics', 'require_order' => true, 'sort' => 20],
        ['name' => '商品质量', 'code' => 'quality', 'require_order' => true, 'sort' => 30],
        ['name' => '退换货', 'code' => 'return', 'require_order' => true, 'sort' => 40],
        ['name' => '支付问题', 'code' => 'payment', 'require_order' => false, 'sort' => 50],
        ['name' => '账户问题', 'code' => 'account', 'require_order' => false, 'sort' => 60],
        ['name' => '投诉建议', 'code' => 'complaint', 'require_order' => false, 'sort' => 70],
        ['name' => '其他', 'code' => 'other', 'require_order' => false, 'sort' => 80],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            CsTicketType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['is_active' => true],
            );
        }
    }
}
