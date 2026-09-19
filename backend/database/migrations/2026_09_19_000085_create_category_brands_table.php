<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 分类可选品牌（分类 ↔ 品牌 多对多）
 *
 * 品牌与分类是两个**正交**维度：一个品牌可出现在多个分类下（苹果既在手机也在平板），
 * 一个分类下可有多个品牌，二者互不隶属，共同描述商品。本表只表达「后台把哪些品牌
 * 挂到了哪个分类上」，即前台按分类浏览时该给用户呈现哪些品牌，并非强制约束
 * （未配置的分类不做品牌限制）。
 *
 * 兼容性：仅新增表，不改动 brands / categories / products 现有结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_brands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->comment('分类');
            $table->unsignedBigInteger('brand_id')->comment('该分类可选品牌');
            $table->integer('sort')->default(0)->comment('展示排序，越大越靠前');
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            $table->unique(['category_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_brands');
    }
};
