<?php

namespace App\Http\Controllers;

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use Illuminate\Http\Response;

/**
 * 站点地图（CMS-202）
 *
 * 决策 D8：由后端在 `GET /sitemap.xml` 直接产出 XML —— 数据在后端，不需要
 * 构建期预渲染；robots.txt 增加 Sitemap 行指向它。
 *
 * ⚠️ URL 一律用 `config('cms.site_url')`（**前台站点**）拼装：sitemap 由后端产出
 * 但列的是前台路由，生产上两者通常不同源，用 APP_URL 会生成一堆 API 域名的死链。
 *
 * 收录范围（与计划文档一致）：
 * - 静态核心页（config('cms.static_pages')）
 * - 已发布帮助文章 `/service-center/faq/{id}`（CsFaqArticle 对外就是 int id）
 * - 已启用单页 `/p/{slug}`
 *
 * ⚠️ 排除「公告」承载栏目（CMS-204）：公告的正式入口是 `/announcements/{id}`，
 * 若按帮助文章收录会得到同一内容的第二个 URL（重复内容，且指向错误的规范地址）。
 *
 * 不含商品/分类：量级大，应走 sitemap index + 分片（见计划文档 §7 遗留）。
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $siteUrl = (string) config('cms.site_url');
        $announcementCarrierId = CsFaqCategory::announcementCarrierId();
        $urls = [];

        foreach ((array) config('cms.static_pages', []) as $path) {
            $urls[] = ['loc' => $siteUrl.$path, 'lastmod' => null];
        }

        $articles = CsFaqArticle::query()
            ->published()
            ->whereHas('category', fn ($q) => $q
                ->where('type', CsFaqCategory::TYPE_CHANNEL)
                ->when($announcementCarrierId, fn ($qq) => $qq->where('id', '!=', $announcementCarrierId)))
            ->orderByDesc('updated_at')
            ->get(['id', 'updated_at']);

        foreach ($articles as $article) {
            $urls[] = [
                'loc' => $siteUrl.'/service-center/faq/'.$article->id,
                'lastmod' => $article->updated_at?->toAtomString(),
            ];
        }

        $pages = CsFaqCategory::query()
            ->where('type', CsFaqCategory::TYPE_PAGE)
            ->where('is_active', true)
            ->whereNotNull('slug')
            ->orderByDesc('updated_at')
            ->get(['slug', 'updated_at']);

        foreach ($pages as $page) {
            $urls[] = [
                'loc' => $siteUrl.'/p/'.$page->slug,
                'lastmod' => $page->updated_at?->toAtomString(),
            ];
        }

        return response($this->toXml($urls), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * 拼装 urlset（手写串接而非 Blade 视图：无模板继承、无转义歧义，XML 就这么几行）
     *
     * @param  list<array{loc: string, lastmod: string|null}>  $urls
     */
    private function toXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'."\n";

            if ($url['lastmod'] !== null) {
                $xml .= '    <lastmod>'.$url['lastmod'].'</lastmod>'."\n";
            }

            $xml .= "  </url>\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
