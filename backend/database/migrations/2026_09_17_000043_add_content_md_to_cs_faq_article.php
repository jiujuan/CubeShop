<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS-112 富文本改造：帮助中心正文增加 markdown 源列 `content_md`
 *
 * 背景：后台正文编辑器由「HTML 富文本 textarea + 插入标签工具条」换成 md-editor-v3
 * （Vue 3 markdown 编辑器），作者写的是 markdown。落库仍保留一列**渲染产物** `content`：
 * - `content_md`  = markdown 源（编辑器回显用，round-trip 不失真）
 * - `content`     = 渲染后的 HTML（用户端 / 后台预览 v-html 渲染，仍过 HtmlSanitizer 白名单）
 *
 * 这样 API 契约与两个前端的展示层**零改动**，既有净化链路继续有效；两列的一致性由
 * `CsFaqArticle` 的写入器保证（见模型 saving 钩子）。
 *
 * 为什么不给列加 comment：SQLite 不支持列注释，加了会破坏双库一致性（本迁移刻意不加）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_article') || Schema::hasColumn('cs_faq_article', 'content_md')) {
            return;
        }

        Schema::table('cs_faq_article', function (Blueprint $table): void {
            $table->text('content_md')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cs_faq_article') || ! Schema::hasColumn('cs_faq_article', 'content_md')) {
            return;
        }

        Schema::table('cs_faq_article', function (Blueprint $table): void {
            $table->dropColumn('content_md');
        });
    }
};
