<?php

use App\Models\CsFaqArticle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * CS-112 富文本改造：存量正文一次性转 markdown，回填 `content_md`
 *
 * 背景：正文此前是 HTML 富文本，换 markdown 编辑器后 `content_md` 是编辑器唯一可读的源；
 * 存量行为空会导致编辑旧文章时编辑器里显示一堆 HTML 标签，故一次性把 HTML 转成 markdown。
 *
 * 两条约定：
 * 1. **同时重写 `content`** —— 转完 markdown 后由模型写入器重新渲染 HTML，使
 *    「`content` = 渲染(`content_md`) 后再净化」这一不变式对存量行同样成立；
 *    否则下次编辑保存时渲染结果会与历史 `content` 不一致（同一篇文章两种排版）。
 * 2. **转换是有损的**：`strip_tags=true` 表示没有 markdown 等价物的标签（如 `<mark>`、
 *    `<sub>`、表格 colspan/rowspan、`<input>` 任务框）会退化为纯文本。行内样式本就被
 *    净化器剥掉（`ALLOWED_ATTRS` 刻意不含 style/class），故不受影响。
 *
 * 幂等：只处理 `content_md` 为 NULL 的行（分批 200），重复执行不会二次转换。
 * 与 000042 一样，迁移内引用应用层渲染器/净化器是刻意为之：保证存量与新数据同口径。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cs_faq_article') || ! Schema::hasColumn('cs_faq_article', 'content_md')) {
            return;
        }

        $converter = new HtmlConverter([
            // 不在 markdown 里保留原始 HTML —— 与渲染器 html_input=escape 的口径一致
            'strip_tags' => true,
            // # 号标题比 setext（下划线式）更可预测，也便于作者手改
            'header_style' => 'atx',
            // 链接保留 [文字](url) 形式，而不是退化成裸链接
            'use_autolinks' => false,
            'suppress_errors' => true,
        ]);

        CsFaqArticle::query()
            ->whereNull('content_md')
            ->chunkById(200, function ($articles) use ($converter): void {
                /** @var CsFaqArticle $article */
                foreach ($articles as $article) {
                    $html = (string) $article->getRawOriginal('content');

                    if (trim($html) === '') {
                        continue;
                    }

                    $markdown = trim($converter->convert($html));

                    if ($markdown === '') {
                        continue;
                    }

                    // 赋 content_md 即触发写入器：content 会被重新渲染 + 净化
                    $article->content_md = $markdown;
                    $article->save();
                }
            });
    }

    public function down(): void
    {
        // 单向数据迁移，不回滚：回滚会丢掉 markdown 源，且 content 已按新口径重写，
        // 恢复旧 HTML 已无来源（与 000042「净化不可逆」同理）
    }
};
