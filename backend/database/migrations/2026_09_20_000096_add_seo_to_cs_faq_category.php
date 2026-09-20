<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 内容中心 CMS 二期（CMS-202）：栏目 SEO 三列
 *
 * 为什么挂在栏目（cs_faq_category）而不是内容行（cs_faq_article.page_fields）：
 * `seo_title`/`keywords`/`description` 描述的是**页面身份**（URL 级别），
 * 与单页选了哪个模板无关；放 page_fields 会随模板切换而丢失，
 * 也没法给 `type=channel` 的栏目复用。
 *
 * 双库兼容：三列都是普通 nullable string，无需专有类型。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_category')) {
            return;
        }

        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->string('seo_title', 128)->nullable()->comment('浏览器标题，空则回落栏目名');
            $table->string('seo_keywords', 255)->nullable()->comment('meta keywords');
            $table->string('seo_description', 255)->nullable()->comment('meta description');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cs_faq_category')) {
            return;
        }

        Schema::table('cs_faq_category', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_keywords', 'seo_description']);
        });
    }
};
