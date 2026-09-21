<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流轨迹查询（V1.1 三期）：运单手机号冗余
 *
 * 快递100 实时查询对「顺丰速运 / 顺丰快运 / 中通快递」强制要求收、寄件人手机号，
 * 电商虚拟号还需截取「-」后的后四位。手机号属于下单时刻的收货快照，
 * 因此按 `company_name` 同款做法冗余进 shippings，避免查询期回查订单。
 *
 * 历史数据不在此迁移回填：运行时由 Shipping::resolvePhone() 回落到
 * orders.address_snapshot.contact_phone 兜底（见 TracePullService）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shippings', function (Blueprint $table) {
            $table->string('phone', 30)
                ->nullable()
                ->after('tracking_no')
                ->comment('收/寄件人手机号快照（顺丰/中通轨迹查询必填）');
        });
    }

    public function down(): void
    {
        Schema::table('shippings', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
