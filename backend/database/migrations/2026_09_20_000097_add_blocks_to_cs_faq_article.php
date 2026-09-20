<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 内容中心 CMS 二期（CMS-203）：单页区块化正文
 *
 * `blocks` 与 `page_fields` 并列而不是复用同一列：
 * - `page_fields` 是**键值对象**（固定版式，schema 由模板决定）；
 * - `blocks` 是**有序数组**（自由编排，schema 由每个区块自己的类型决定）。
 * 结构不同，混在一列里会让「这是对象还是数组」变成运行时才知道的事。
 *
 * 向后兼容（决策 D6）：只有 `template=blocks` 的单页会写这一列，
 * about/contact 等固定模板单页保持 page_fields 不动 —— 两套渲染路径并存但不交叉。
 *
 * 双库兼容：JSON 统一 $table->json()（SQLite→text，PG→jsonb）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_article') || Schema::hasColumn('cs_faq_article', 'blocks')) {
            return;
        }

        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->json('blocks')->nullable()->comment('区块化正文（有序数组），固定模板单页为 null');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cs_faq_article') || ! Schema::hasColumn('cs_faq_article', 'blocks')) {
            return;
        }

        Schema::table('cs_faq_article', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });
    }
};
