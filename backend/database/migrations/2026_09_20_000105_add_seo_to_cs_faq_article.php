<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新闻中心后期增强（§7）：文章级 SEO 三列
 *
 * 栏目级已有 seo_title/keywords/description；本迁移把同样的三个字段落到**文章**表，
 * 让每篇新闻可单独设置 meta（详情页优先用文章级，缺则回落栏目级/标题摘要）。
 * 可空、长度与栏目级一致（title 128 / keywords 255 / description 255）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->string('seo_title', 128)
                ->nullable()
                ->after('summary')
                ->comment('文章 SEO 标题（详情页 meta，缺则回落栏目/标题）');
            $table->string('seo_keywords', 255)
                ->nullable()
                ->after('seo_title')
                ->comment('文章 SEO 关键词');
            $table->string('seo_description', 255)
                ->nullable()
                ->after('seo_keywords')
                ->comment('文章 SEO 描述');
        });
    }

    public function down(): void
    {
        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_keywords', 'seo_description']);
        });
    }
};
