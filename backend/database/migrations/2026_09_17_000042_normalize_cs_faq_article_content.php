<?php

use App\Models\CsFaqArticle;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * CS-112 缺陷 #1：存量帮助中心正文一次性清洗
 *
 * 背景：正文是 HTML 富文本（设计文档 §4），此前用户端按**纯文本**渲染导致 `<p>` 可见；
 * 改为 v-html 渲染后，写入侧已由 `CsFaqArticle::setContentAttribute()` 统一净化，
 * 但存量行是「净化上线前」写入的，必须补一次清洗，否则历史脏数据仍会直接进浏览器。
 *
 * 幂等：净化结果稳定（clean(clean(x)) === clean(x)），只有内容确实变化时才写库。
 * 说明：迁移内引用应用层净化器是刻意为之 —— 净化规则将来若调整，本迁移不回改，
 *       保证「一次性、按当时规则」执行，与新数据的处理口径一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_article')) {
            return;
        }

        CsFaqArticle::query()->chunkById(200, function ($articles): void {
            /** @var CsFaqArticle $article */
            foreach ($articles as $article) {
                $raw = (string) $article->getRawOriginal('content');
                $clean = HtmlSanitizer::clean($raw);

                if ($clean === $raw) {
                    continue;
                }

                $article->content = $clean;
                $article->save();
            }
        });
    }

    public function down(): void
    {
        // 净化不可逆（被剥掉的脚本/事件属性正是要清除的东西），不做回滚
    }
};
