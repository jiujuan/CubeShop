<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品分类（支持二级）
 * 对应设计文档：2.3 categories
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父分类，0=一级');
            $table->string('name', 128)->comment('分类名');
            $table->integer('sort')->default(0)->comment('排序，越大越靠前');
            $table->smallInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
