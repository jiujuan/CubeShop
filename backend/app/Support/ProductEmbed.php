<?php

namespace App\Support;

/**
 * 正文内联商品卡（新闻 / 帮助中心正文里的「种草卡」）
 *
 * 作者在 markdown 里用**独占一段**的标记指定商品卡的位置：
 *
 *     [[product:01HXABCDEFGHJKMNPQRSTVWXYZ]]
 *
 * 渲染链路（都在 `CsFaqArticle` 的 saving 钩子里一次完成）：
 *
 *     markdown ──MarkdownRenderer──► HTML ──本类──► 占位容器 ──HtmlSanitizer──► 落库
 *
 * 为什么正文里只放**空占位**、卡片由前台读取侧渲染：
 * `content` 是保存时派生一次并持久化的，卡片 HTML 一旦写死，价格/主图/库存会冻结在
 * 保存那一刻（改价后正文还是老价格）。所以正文只承载「这里有一张商品卡」的位置信息，
 * 真正的卡片由前台拿详情接口的实时商品数据渲染（`embedded_products`）。
 *
 * 为什么用 `id` 而不是自定义 `data-*` 属性承载商品标识：
 * `HtmlSanitizer` 的 `id` 本来就在白名单里，且会按 `SAFE_ID` 校验值（首字符须为字母，
 * 其余字母/数字/`_ : . -`）。本类生成的 `news-product-{public_id}` 恰好合规，占位容器
 * 因此能正常穿过净化 —— **不必为它给唯一安全边界开口子**，白名单能不动就不动。
 *
 * 标记只认「独占一段」的写法（渲染后是 `<p>[[product:x]]</p>`）：行内出现的标记按字面
 * 保留为普通文本。这样占位容器一定落在正文顶层块之间，前台才能安全地按它切分正文。
 */
final class ProductEmbed
{
    /** 占位容器 id 前缀（后接商品 public_id） */
    public const ID_PREFIX = 'news-product-';

    /** 占位容器里的回退文案：前台未渲染时（或后台预览里）至少看得见这里有一张卡 */
    public const FALLBACK_LABEL = '［商品卡］';

    /**
     * 作者书写的标记，须独占一段。
     *
     * 字符集放宽到 `[A-Za-z0-9]{1,40}`（ULID 是 26 位 Crockford base32），
     * 不写死长度，避免将来改用别的对外标识时整批标记失效。
     */
    private const TOKEN = '/<p>\s*\[\[product:([A-Za-z0-9]{1,40})\]\]\s*<\/p>/';

    /**
     * 反解占位容器：只认 id，不认标签与标签里的文案。
     *
     * 这样将来改回退文案、或给容器加别的属性，都不会影响「正文里内联了哪些商品」的判断。
     */
    private const PLACEHOLDER_ID = '/id="'.self::ID_PREFIX.'([A-Za-z0-9]{1,40})"/';

    /** 标记的任意出现（含行内），用于解析 markdown 源 */
    private const TOKEN_ANYWHERE = '/\[\[product:([A-Za-z0-9]{1,40})\]\]/';

    /** 占位容器的 id */
    public static function placeholderId(string $publicId): string
    {
        return self::ID_PREFIX.$publicId;
    }

    /** 作者在 markdown 里书写的标记（后台「插入正文」用它拼插入内容） */
    public static function token(string $publicId): string
    {
        return '[[product:'.$publicId.']]';
    }

    /**
     * 把「独占一段」的商品标记换成占位容器。
     *
     * 必须在 `HtmlSanitizer` **之前**调用：生成的 `<div id>` 属于白名单内的标签+属性，
     * 走一遍净化后仍会保留，从而「落库的 HTML 一定经过白名单」这条不变式继续成立。
     */
    public static function tokenize(?string $html): string
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, '[[')) {
            return $html;
        }

        return preg_replace(
            self::TOKEN,
            '<div id="'.self::ID_PREFIX.'$1">'.self::FALLBACK_LABEL.'</div>',
            $html
        ) ?? $html;
    }

    /**
     * 正文里内联了哪些商品（按出现顺序，去重）
     *
     * @return list<string> public_id 列表
     */
    public static function extractIds(?string $html): array
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, self::ID_PREFIX)) {
            return [];
        }

        if (preg_match_all(self::PLACEHOLDER_ID, $html, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * markdown **源**里提到的商品（不要求独占一段，行内也算）
     *
     * 用于保存时把「正文提到过、但没勾选关联」的商品补进关联集：漏勾选会导致前台该处
     * 一片空白（出口只认已发布的关联商品），这类静默失败很难自查，索性以正文为准兜住。
     *
     * @return list<string> public_id 列表
     */
    public static function extractTokenIds(?string $markdown): array
    {
        $markdown = (string) $markdown;

        if ($markdown === '' || ! str_contains($markdown, '[[product:')) {
            return [];
        }

        if (preg_match_all(self::TOKEN_ANYWHERE, $markdown, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1] ?? []));
    }
}
