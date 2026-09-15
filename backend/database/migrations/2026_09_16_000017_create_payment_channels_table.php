<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付渠道配置表（收银台方案 §4.1(1)）
 *
 * 密钥等敏感项在 config JSON 内以 Crypt::encryptString 加密存储（见 §5.3），
 * 非敏感项（app_id / mch_id / gateway）明文，便于后台直接回显。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('channel', 32)->unique()->comment('wechat/alipay/balance/offline/mock');
            $table->string('name', 64)->comment('展示名：微信支付');
            $table->boolean('enabled')->default(false)->comment('是否对前台开放');
            $table->boolean('sandbox')->default(true)->comment('是否走渠道沙箱');
            $table->integer('sort')->default(0);
            $table->jsonb('config')->nullable()->comment('渠道参数（敏感键加密）');
            $table->string('notify_url', 255)->nullable()->comment('异步回调地址');
            $table->string('return_url', 255)->nullable()->comment('支付后前端回跳地址');
            $table->string('remark', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
