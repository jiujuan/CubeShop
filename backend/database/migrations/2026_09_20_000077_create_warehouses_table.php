<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 仓库档案（WMS 计划 P0 / README §3-D2）
 *
 * CubeShop 原先不存在「仓库」概念，本表把仓库引入平台：
 * WMS 对接配置、SKU 映射、履约出库单、退货入库单均以仓库为归属维度。
 * 本期先建表 + 预置 1 个默认仓（WarehouseSeeder），不做商品/库存的仓库维度改造。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            // 仓库编码：平台侧唯一标识（如 WH_DEFAULT），WMS 映射与日志引用
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            // 联系人信息（WMS 对接/退货地址沟通用）
            $table->string('contact_name', 32)->nullable();
            $table->string('contact_phone', 20)->nullable();
            // 省市区（取自 shared/region-dict，存中文名，与 user_addresses 口径一致）
            $table->string('province', 32)->nullable();
            $table->string('city', 32)->nullable();
            $table->string('district', 32)->nullable();
            $table->string('address', 200)->nullable();
            // 1=启用 0=停用；停用后不允许推送（P1 起校验）
            $table->tinyInteger('status')->default(1);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
