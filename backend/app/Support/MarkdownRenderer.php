<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown → HTML 渲染（帮助中心 FAQ 正文）
 *
 * 帮助中心正文的**源**是 markdown（后台用 md-editor-v3 编辑），落库时由本类渲染成
 * HTML 存入 `cs_faq_article.content`；用户端/后台预览直接 v-html 渲染 HTML 产物。
 *
 * 安全策略是分层的，但**唯一安全边界仍是 App\Support\HtmlSanitizer**：
 * 1. html_input=escape —— markdown 里内嵌的原始 HTML 标签一律转义成文本，不放行；
 * 2. allow_unsafe_links=false —— commonmark 直接丢弃 javascript:/data: 一类危险协议链接；
 * 3. GFM 自带的 DisallowedRawHtml 扩展，额外兜住 script/iframe 等原始 HTML 块；
 * 4. 产物再过一遍 HtmlSanitizer（白名单 + 协议校验）—— 渲染器的输出不被信任。
 *
 * 锚点：开启 HeadingPermalink 但 `insert=none` + `apply_id_to_heading=true`，
 * 即只给标题加 id（正文里可写 [文字](#content-xxx) 跳转），不插入符号链接。
 */
final class MarkdownRenderer
{
    /**
     * 转换器较重（要建 Environment + 挂扩展），进程内复用一份
     */
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return self::converter()->convert($markdown)->getContent();
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter instanceof MarkdownConverter) {
            return self::$converter;
        }

        $environment = new Environment([
            // 内嵌 HTML 转义成文本（不放行）
            'html_input' => 'escape',
            // 危险协议链接直接丢弃
            'allow_unsafe_links' => false,
            // 标题加 id 供锚点跳转，但不插入符号链接
            'heading_permalink' => [
                'insert' => 'none',
                'apply_id_to_heading' => true,
                'id_prefix' => 'content',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        // GFM：表格 / 删除线 / 自动链接 / 任务列表 + DisallowedRawHtml
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        return self::$converter = new MarkdownConverter($environment);
    }
}
