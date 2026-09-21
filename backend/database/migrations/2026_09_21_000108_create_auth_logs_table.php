<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 认证日志表（登录 / 注册 / 登出）
 *
 * 与通用操作日志 `sys_operation_log` 分开建表的原因：
 * 认证排障需要 `success` / `fail_reason` / `identifier` / `device_id` / `token_id`
 * 这类专用维度，通用表没有这些列，强行塞 JSON 会难以聚合与检索。
 *
 * 仅 created_at，无 updated_at（日志不可变）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_logs', function (Blueprint $table) {
            $table->id();
            // 成功时的用户 ID；管理员=sys_user.id，买家=users.id，失败时多为 null
            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('用户ID（成功时）；管理员=sys_user，买家=users');
            // 身份类型：admin（后台管理员）/ customer（买家）
            $table->string('actor_type', 16)->default('customer')->comment('身份类型 admin/customer');
            // 登录标识（用户名/手机号），失败也可能为空
            $table->string('identifier', 120)->nullable()->index()->comment('登录/注册标识（用户名或手机号）');
            // 事件：login / register / logout
            $table->string('event', 24)->index()->comment('login/register/logout');
            // 是否成功
            $table->boolean('success')->default(false)->index()->comment('是否成功');
            // 失败原因（内部明细，响应层不向前端暴露具体差异）
            $table->string('fail_reason', 255)->nullable()->comment('失败原因（内部明细）');
            // 来源 IP（IPv6 最长 45）
            $table->string('ip', 45)->nullable()->comment('来源IP');
            $table->string('user_agent', 512)->nullable()->comment('User-Agent');
            // auth_tokens.id（设备记录主键）
            $table->unsignedBigInteger('device_id')->nullable()->comment('设备ID（auth_tokens.id）');
            // auth_tokens.token_id（= personal_access_tokens.id）
            $table->string('token_id', 64)->nullable()->comment('本次签发的token标识');
            // 扩展明细（如锁定剩余秒数 / 校验阶段 / 异常栈摘要）
            $table->text('detail')->nullable()->comment('扩展明细 JSON');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_logs');
    }
};
