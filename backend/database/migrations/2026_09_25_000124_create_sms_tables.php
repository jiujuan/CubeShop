<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 短信渠道基础表（短信渠道计划 第一期）
 *
 * 设计文档：docs/design/CubeShop_SMS_Channel_Design_v1.0.md §D1 / §D5
 *
 * 两张表：
 * - `sms_configs`：每服务商一行（provider 唯一）。凭证 Secret 走 `*_enc` 密文列
 *   （`Crypt::encryptString`），与 `WmsConfig` 同款；`extra` 为 JSON 扩展列，
 *   二期腾讯云放 SdkAppId，避免二次迁移。
 * - `sms_logs`：每次发送留痕。**手机号只存脱敏值**，模板参数（含验证码）不落库。
 *   发送记录用 `timestamps()`：`updated_at` 无业务含义但不额外维护开关，保持 Eloquent 约定。
 *
 * 迁移同时写入三行渠道种子（mock / aliyun / tencent），默认启用 mock：
 * 没有阿里云凭证时系统仍然可用（Mock 只留痕、不发真短信），但总开关 `sms.enabled`
 * 默认关闭，未显式开启前不会消耗任何短信额度。
 *
 * ⚠️ 不硬编码自增 id（PG 序列不回退），交由数据库分配。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->unique()->comment('服务商标识：mock|aliyun|tencent');
            $table->string('name', 64)->comment('展示名');
            $table->string('access_key_id', 128)->nullable()->comment('AccessKey ID（非机密，明文存）');
            $table->text('access_key_secret_enc')->nullable()->comment('AccessKey Secret（Crypt 密文，禁止出口）');
            $table->string('sign_name', 64)->nullable()->comment('短信签名');
            $table->string('region', 32)->default('cn-hangzhou')->comment('地域；endpoint 固定不入库');
            $table->json('extra')->nullable()->comment('服务商扩展参数（二期腾讯云 SdkAppId）');
            $table->boolean('is_enabled')->default(false)->comment('启用标志；服务层保证全局最多一行启用');
            $table->string('remark', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_config_id')->nullable()->index();
            $table->string('provider', 32)->index()->comment('冗余记录：渠道被删后日志仍可读');
            $table->string('phone_masked', 32)->comment('脱敏手机号 138****8000');
            $table->string('scene', 32)->default('general')->index()->comment('场景：general|register|reset_password|test');
            $table->string('template_code', 64)->nullable();
            $table->string('status', 16)->index()->comment('sent|failed|skipped');
            $table->string('error_code', 64)->nullable();
            $table->string('error_msg', 255)->nullable();
            $table->string('biz_id', 64)->nullable()->comment('服务商回执流水号（阿里云 BizId）');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        $now = now();

        DB::table('sms_configs')->insert([
            [
                'provider' => 'mock',
                'name' => 'Mock 渠道（不发真短信）',
                'region' => 'cn-hangzhou',
                'is_enabled' => true,
                'remark' => '开发/测试默认渠道：只写发送日志，不请求任何服务商',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'aliyun',
                'name' => '阿里云短信',
                'region' => 'cn-hangzhou',
                'is_enabled' => false,
                'remark' => '需填写 AccessKey 与已审核签名/模板后启用',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'tencent',
                'name' => '腾讯云短信',
                'region' => 'ap-guangzhou',
                'is_enabled' => false,
                'remark' => '二期提供适配器，当前不可启用',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_configs');
    }
};
