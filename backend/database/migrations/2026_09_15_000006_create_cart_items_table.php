<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 购物车项
 * 对应设计文档：2.5 cart_items，唯一约束 (user_id, sku_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('用户');
            $table->unsignedBigInteger('sku_id')->comment('SKU');
            $table->integer('quantity')->default(1)->comment('数量');
            $table->timestamps();

            $table->unique(['user_id', 'sku_id']);
            $table->foreign('user_id')->references('id')->on('sys_user')->cascadeOnDelete();
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
