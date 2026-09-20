<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新闻中心后期增强（§7）：文章标签（专题/标签聚合）
 *
 * 用 JSON 数组列承载 tags（设计文档：repeater 字段即可，不必新表）。
 * 后台文章表单以「逗号分隔」录入，后端归一为字符串数组；前台按 tag 聚合专题页。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->json('tags')
                ->nullable()
                ->after('seo_description')
                ->comment('文章标签（JSON 字符串数组，用于专题/标签聚合）');
        });
    }

    public function down(): void
    {
        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
