<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * 站点 SEO 端点（CMS-202 决策 D8）
 *
 * 路径必须位于**站点根**下（`/sitemap.xml`、`/robots.txt`），搜索引擎不认 `/api/...`，
 * 因此不能放进 api 路由文件。
 *
 * ⚠️ 刻意放在独立文件、由 bootstrap/app.php 的 `then:` 注册，**不挂 `web` 中间件组**：
 * 抓取请求不需要会话与 Cookie，而 `web` 组会启动数据库会话（本项目是纯 API 后端，
 * `SESSION_DRIVER=database` 却没有 sessions 表），挂上去会直接 500。
 * 同理也不带 `api` 组 —— 那只为 JSON 接口准备，还会引入限流/枚举防护等无关中间件。
 *
 * ⚠️ robots.txt 由路由产出而不是 public/robots.txt 静态文件：静态文件里只能硬编码域名，
 * 换环境就会指向错误站点；这里统一取 config('cms.site_url')（前台域名）。
 * 因为 Web 服务器会优先返回 public/ 下的同名静态文件，此处**已删除** public/robots.txt。
 */
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/robots.txt', function () {
    return response(
        "User-agent: *\nDisallow:\n\nSitemap: ".config('cms.site_url')."/sitemap.xml\n",
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
})->name('robots');
