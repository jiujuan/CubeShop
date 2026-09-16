<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 买家用户表（前台注册用户）
 *
 * 拆分背景：sys_user 收敛为后台管理员专表（super_admin / operator），
 * 买家迁至本表，二者账号体系物理隔离。
 * 方案文档：docs/design/CubeShop_UserTable_Split_Analysis.md
 *
 * 字段沿用 sys_user 的通用认证字段；当前 sys_user 尚无管理侧专属字段
 * （MFA / IP 白名单等均为规划项），故此处保持结构对齐，便于按原 ID 直接搬迁。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 64)->unique()->comment('登录名');
            $table->string('email', 128)->nullable()->unique()->comment('邮箱');
            $table->string('phone', 20)->nullable()->unique()->comment('手机号');
            $table->string('password', 255)->comment('密码哈希');
            $table->string('nickname', 64)->nullable()->comment('昵称');
            $table->string('avatar', 512)->nullable()->comment('头像 URL');
            $table->smallInteger('status')->default(1)->comment('1=正常 0=禁用');
            $table->timestamp('last_login_at')->nullable()->comment('最后登录时间');
            $table->string('last_login_ip', 45)->nullable()->comment('最后登录 IP');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
