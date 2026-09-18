<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS 对接配置（WMS 计划 P0 / README §3-D2、D6、D9）
 *
 * 一个仓库一条配置（unique(warehouse_id)）。凭证类字段以 `_enc` 后缀存储密文
 * （模型 accessor/mutator 自动 Crypt::encryptString 加解密），接口出口一律掩码，
 * 遵循 SEC-01「密钥不落地明文、只写不读」。
 *
 * 京东云仓（provider=jd_cloud）字段本期一并建出（可空），P8 启用，避免二次改表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_configs', function (Blueprint $table) {
            $table->id();
            // 归属仓库：删仓库需先删配置（restrict），避免凭证悬挂
            $table->unsignedBigInteger('warehouse_id');
            // 服务商：cainiao 菜鸟（本期）/ jd_cloud 京东云仓（P8）
            $table->string('provider', 16)->default('cainiao');
            // 总开关 + 两个自动推送开关
            $table->boolean('enabled')->default(false);
            $table->boolean('auto_push')->default(true);          // 支付成功自动推送出库单
            $table->boolean('auto_push_return')->default(true);   // 退货审核通过自动推送入库单
            // 推送失败重试次数（0..10，业务层校验）
            $table->unsignedTinyInteger('push_retry_times')->default(3);
            // SKU 映射方式：same 回落平台 sku_code / manual 必须命中映射
            $table->string('sku_mapping_mode', 16)->default('same');

            // ---- 菜鸟（奇门）凭证 ----
            $table->string('app_key', 64)->nullable();
            // 密文（TEXT）：Crypt::encryptString 结果超 255，不能放 varchar
            $table->text('app_secret_enc')->nullable();
            $table->text('access_token_enc')->nullable();
            $table->string('customer_id', 64)->nullable();
            $table->string('owner_no', 64)->nullable();          // 货主编码
            $table->string('warehouse_code', 64)->nullable();    // 菜鸟仓库编码
            $table->string('warehouse_no', 64)->nullable();      // 菜鸟仓库物理编码
            // 环境：prod 生产 / sandbox 沙箱（沙箱或未配真实凭证时走 Mock 兜底）
            $table->string('api_env', 16)->default('sandbox');
            // 回调识别 token：随机 32 位，回调路由凭它定位仓库（不暴露自增 id）
            $table->string('callback_token', 64)->unique();
            // 供应商扩展配置（奇门版本号等，P2 使用）
            $table->json('extra_config')->nullable();
            $table->string('remark', 255)->nullable();
            $table->timestamps();

            $table->unique('warehouse_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_configs');
    }
};
