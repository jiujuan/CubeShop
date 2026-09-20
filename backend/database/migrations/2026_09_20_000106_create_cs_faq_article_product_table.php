<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新闻中心后期增强（§7）：文章↔商品关联（种草挂载）
 *
 * 一篇新闻可关联多件商品（种草），一件商品也可被多篇新闻引用。
 * 用独立关联表（而非给商品加字段）——新闻与商品是多对多，且只新闻侧需要这个关系。
 * 删除任一侧时由数据库外键级联清理关联行。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_faq_article_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id')->comment('新闻文章 id');
            $table->unsignedBigInteger('product_id')->comment('商品 id');
            $table->integer('sort')->default(0)->comment('展示顺序');
            $table->timestamps();

            $table->unique(['article_id', 'product_id'], 'article_product_unique');
            $table->foreign('article_id')
                ->references('id')->on('cs_faq_article')
                ->onDelete('cascade');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');
            $table->index('product_id', 'article_product_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_faq_article_product');
    }
};
