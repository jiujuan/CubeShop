<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-06：Token 设备指纹表
 *
 * Sanctum 的 `personal_access_tokens` 只记录 name / abilities / last_used_at，
 * 没有来源 IP 与 User-Agent，无法回答"这个 Token 是从哪台设备登录的"。
 *
 * 与其修改 Sanctum 的表结构（会与 vendor 升级冲突），这里独立建表按 token_id 关联：
 * - 登录时写入一条（ip / ua / 设备标签）；
 * - 「最近登录设备」列表由此表 JOIN personal_access_tokens 得出；
 * - 踢下线即删除对应的 personal_access_tokens 记录（并清理本表）。
 *
 * 注意：本表**不重复存储** last_used_at，统一读 personal_access_tokens 的同名字段，
 * 避免两处时间戳不一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_tokens')) {
            return;
        }

        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->id();
            // 关联 personal_access_tokens.id（不建外键：Sanctum 表由 vendor 管理，
            // 软删除/清表策略不同，外键会在清理时造成意外失败）
            $table->unsignedBigInteger('token_id')->index();
            $table->string('tokenable_type', 191);
            $table->unsignedBigInteger('tokenable_id');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device_label', 128)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tokenable_type', 'tokenable_id'], 'auth_tokens_tokenable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');
    }
};
