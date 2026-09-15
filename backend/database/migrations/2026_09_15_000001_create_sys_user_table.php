<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 系统用户表（买家 + 管理员）
 * 对应设计文档：2.1.1 sys_user
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_user', function (Blueprint $table) {
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
        Schema::dropIfExists('sys_user');
    }
};
