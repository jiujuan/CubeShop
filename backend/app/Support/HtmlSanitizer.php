<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * 富文本白名单净化器（CS-112 缺陷 #1）
 *
 * 帮助中心正文由后台以 HTML 富文本维护（设计文档 §4：支持图片、表格、锚点），
 * 用户端与后台预览都用 `v-html` 渲染，因此**写入侧必须净化**，否则一条被写入的
 * `<script>`/`onerror` 会在所有买家浏览器里执行（存储型 XSS）。
 *
 * 策略：
 * - 标签与属性双白名单；`script`/`style`/`iframe`/`form`/`svg` 等连同子树整体丢弃，
 *   其他未知标签「去壳保留文字」（避免吃内容）
 * - `href`/`src` 校验协议，`javascript:`/`vbscript:`/`data:text/html` 一律剥离
 * - 无标签的纯文本走「转义 + 换行转 `<br>`」，让手写文本既能保留换行又不可能注入
 * - `target="_blank"` 自动补 `rel="noopener noreferrer"`
 *
 * 调用点（写入唯一入口）：`CsFaqArticle` 的写入器 ——
 * markdown 路径走 `cleanHtml()`（输入是渲染产物），直接写 HTML 的旧路径走 `clean()`；
 * 存量数据清洗见迁移 `2026_09_17_000042`。
 * 读取侧直接渲染库中内容，不再二次净化。
 */
final class HtmlSanitizer
{
    /** 允许的标签（对齐设计文档：段落 / 标题 / 列表 / 表格 / 图片 / 链接 / 代码 / 锚点） */
    public const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'div', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'sub', 'sup', 'mark', 'small',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'blockquote', 'code', 'pre',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
    ];

    /**
     * 允许的属性：`*` 为所有标签通用。
     *
     * 刻意不支持 `style`/`class`：正文排版由前端统一控制，
     * 开放内联样式等于把页面外观的控制权交给了编辑器。
     */
    public const ALLOWED_ATTRS = [
        '*' => ['id', 'title'],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'col' => ['span'],
    ];

    /** 需要校验协议的白名单标签（`a`/`img` 的子集，见 ALLOWED_ATTRS） */
    private const URI_ATTRS = ['href', 'src'];

    /** 命中即整体丢弃（含子树）的标签：这些标签的内容本身就不该出现在正文里 */
    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'label', 'fieldset',
        'link', 'meta', 'base', 'noscript', 'template', 'svg', 'math', 'audio', 'video',
        'source', 'track', 'canvas', 'map', 'area', 'portal', 'dialog',
    ];

    /**
     * 锚点 id 允许的字符（供 `#anchor` 跳转使用，同时避免奇怪的选择器注入）。
     *
     * 首字符须是字母，其余允许字母/数字/`_ : . -`。放行 Unicode 字母（`\p{L}`）是必要的：
     * markdown 标题 id 由标题文本生成，中文标题会得到 `content-如何下单购买商品` 这类 id，
     * 若只允许 ASCII，中文文章的锚点会被整条剥掉。空格、引号、`>`、`=` 等仍被拒（无注入面）。
     */
    private const SAFE_ID = '/^[\p{L}][\p{L}\p{N}_.:\-]*$/u';

    /**
     * 结构性（块级）标签：出现其一即视为「已按 HTML 排版」。
     * 都没有时按「行内片段」处理 —— 把原始换行转成 `<br>`，
     * 否则 HTML 会折叠掉换行，作者写的多行文本（可能夹着 <strong> 等）会挤成一行。
     */
    private const BLOCK_TAG_PATTERN = '/<(p|div|ul|ol|li|table|h[1-6]|blockquote|pre|hr|figure|dl|dd|dt)\b/i';

    /** 数值属性允许的字符（width/height/colspan/rowspan/span） */
    private const SAFE_NUMBER = '/^\d{1,4}$/';

    /**
     * 净化 HTML 片段，返回可安全 `v-html` 的字符串。
     *
     * 带「纯文本 / 行内片段」启发判断，适用于**直接以 HTML 富文本写入**的场景：
     * `CsFaqArticle::setContentAttribute()` 与存量清洗迁移 `2026_09_17_000042`。
     * 若输入本身已是渲染产物（markdown → HTML），请用 `cleanHtml()`，否则会被二次转义。
     */
    public static function clean(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return $html;
        }

        // 纯文本（不含任何标签）：转义 + 换行转 <br>，既保留换行又不可能注入
        if (! preg_match('/<[a-zA-Z\/!]/', $html)) {
            return nl2br(e($html), false);
        }

        // 行内片段（只有 <strong>/<a>/<img> 之类，没有块级标签）：先把换行转成 <br>，
        // 否则作者写的多行内容会被 HTML 折叠成一行
        if (! preg_match(self::BLOCK_TAG_PATTERN, $html)) {
            $html = nl2br($html, false);
        }

        return self::sanitizeHtml($html);
    }

    /**
     * 只做白名单净化，**不做**纯文本 / 行内片段的启发判断。
     *
     * 用于「输入已是渲染产物」的场景：markdown 渲染出的 HTML 里，内嵌原始 HTML 已被渲染器
     * 转义成 `&lt;script&gt;` 这类实体；若再走 `clean()` 的纯文本分支，会被二次转义成
     * `&amp;lt;`（用户看到字面实体）。这里固定走 DOM 解析路径 —— 实体解析回字符、
     * 序列化时重新转义一次，因此既不重复转义也仍然幂等。
     */
    public static function cleanHtml(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return $html;
        }

        return self::sanitizeHtml($html);
    }

    /**
     * DOM 白名单净化核心（`clean()` 与 `cleanHtml()` 共用）
     */
    private static function sanitizeHtml(string $html): string
    {
        $root = self::parse($html);

        if ($root === null) {
            // 解析失败（极端脏数据）→ 退化为纯文本，宁可丢样式也不放行脚本
            return nl2br(e(strip_tags($html)), false);
        }

        $out = new DOMDocument('1.0', 'UTF-8');
        $container = $out->createElement('div');
        $out->appendChild($container);

        foreach (iterator_to_array($root->childNodes) as $child) {
            $cleaned = self::cleanNode($child, $out);
            if ($cleaned !== null) {
                $container->appendChild($cleaned);
            }
        }

        $result = '';
        foreach (iterator_to_array($container->childNodes) as $child) {
            $result .= $out->saveHTML($child);
        }

        return $result;
    }

    /**
     * 把片段解析成一棵以 `<div>` 为根的树；解析失败返回 null
     */
    private static function parse(string $html): ?DOMElement
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div data-cs-sanitize-root="1">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div instanceof DOMElement && $div->getAttribute('data-cs-sanitize-root') === '1') {
                return $div;
            }
        }

        return null;
    }

    /**
     * 递归净化单个节点；返回 null 表示该节点被丢弃
     */
    private static function cleanNode(DOMNode $node, DOMDocument $out): ?DOMNode
    {
        if ($node instanceof DOMText) {
            return $out->createTextNode($node->nodeValue ?? '');
        }

        if (! $node instanceof DOMElement) {
            // 注释 / CDATA / 处理指令：一律丢弃
            return null;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DROP_TAGS, true)) {
            return null;
        }

        // 不在白名单：去壳保留子树（例如 <section>、<font>），避免吃内容
        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $fragment = $out->createDocumentFragment();
            foreach (iterator_to_array($node->childNodes) as $child) {
                $cleaned = self::cleanNode($child, $out);
                if ($cleaned !== null) {
                    $fragment->appendChild($cleaned);
                }
            }

            return $fragment;
        }

        $element = $out->createElement($tag);
        self::copyAttributes($node, $element, $tag);

        foreach (iterator_to_array($node->childNodes) as $child) {
            $cleaned = self::cleanNode($child, $out);
            if ($cleaned !== null) {
                $element->appendChild($cleaned);
            }
        }

        return $element;
    }

    /**
     * 按白名单搬运属性，并做协议/格式校验
     */
    private static function copyAttributes(DOMElement $from, DOMElement $to, string $tag): void
    {
        $allowed = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);

        foreach ($from->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            // on* 事件属性永不通过
            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                continue;
            }

            $value = trim((string) $attribute->nodeValue);

            if ($name === 'id' && ! preg_match(self::SAFE_ID, $value)) {
                continue;
            }

            if (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'span'], true) && ! preg_match(self::SAFE_NUMBER, $value)) {
                continue;
            }

            if (in_array($name, self::URI_ATTRS, true) && ! self::isSafeUrl($value, $tag, $name)) {
                continue;
            }

            if ($name === 'target' && ! in_array($value, ['_blank', '_self'], true)) {
                continue;
            }

            $to->setAttribute($name, $value);
        }

        // 外链新窗口打开时补 rel，防 Reverse Tabnabbing
        if ($tag === 'a' && $to->getAttribute('target') === '_blank') {
            $to->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * URL 白名单：http(s) / 协议相对 / 站内相对路径 / data:image
     */
    private static function isSafeUrl(string $url, string $tag, string $attribute): bool
    {
        if ($url === '') {
            return false;
        }

        // 去掉控制字符（`java\0script:` 之类绕过手法）
        $normalized = preg_replace('/[\x00-\x20\x7f]/', '', $url) ?? '';

        if (preg_match('#^(https?:)?//#i', $normalized) === 1) {
            return true;
        }

        if (str_starts_with($normalized, '/')) {
            return true;
        }

        if ($tag === 'img' && $attribute === 'src' && preg_match('#^data:image/(png|jpeg|jpg|gif|webp|svg\+xml);base64,#i', $normalized) === 1) {
            return true;
        }

        // 相对路径（不含协议）；带冒号的（javascript:、mailto: 之外的自定义协议）一律拒绝
        if (! str_contains(rtrim($normalized, '.'), ':')) {
            return true;
        }

        return false;
    }
}
