<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G5（金额明细治理）：修正 orders.discount_amount 列注释的语义错位
 *
 * 历史迁移 000031 注释误写为「券优惠额」，但代码（OrderService）实际写入的是
 * 「优惠合计 = 券 + 满减」（见 PricingCalculator::recomputePayAmount 口径）。
 * 本迁移在支持列注释的数据库（pgsql）上更正注释；不涉及任何结构或数据变更。
 * SQLite（测试库）不支持列注释 ALTER，此处显式跳过，避免 migrate 报错。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN orders.discount_amount IS '优惠合计(券+满减)，非券优惠额'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN orders.discount_amount IS '券优惠额'");
        }
    }
};
