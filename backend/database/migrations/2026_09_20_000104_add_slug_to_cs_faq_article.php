<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新闻中心后期增强（§7）：文章 slug 语义化 URL
 *
 * 新增 `slug` 列（可空，唯一）：前台详情可由 `/news/{id}` 升级为 `/news/{slug}`。
 * 唯一索引保证 slug 不重复；存量文章 slug 为空，详情仍按 id 兜底解析（向后兼容）。
 * 长度 191 与全站字符串主键/唯一键一致（UTF8mb4 下 varchar(191) 最稳）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->string('slug', 191)
                ->nullable()
                ->after('title')
                ->comment('语义化 URL（新闻详情 /news/{slug}，空则回落 id）');
        });

        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->unique('slug', 'cs_faq_article_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->dropUnique('cs_faq_article_slug_unique');
            $table->dropColumn('slug');
        });
    }
};
