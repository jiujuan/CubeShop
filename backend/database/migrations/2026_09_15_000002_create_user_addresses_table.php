<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收货地址表
 * 对应设计文档：2.2 user_addresses
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('所属用户');
            $table->string('contact_name', 64)->comment('收货人');
            $table->string('contact_phone', 20)->comment('手机');
            $table->string('province', 64)->nullable()->comment('省');
            $table->string('city', 64)->nullable()->comment('市');
            $table->string('district', 64)->nullable()->comment('区');
            $table->string('detail_address', 255)->comment('详细地址');
            $table->boolean('is_default')->default(false)->comment('是否默认');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('sys_user')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
