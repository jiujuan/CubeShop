<?php

use Illuminate\Support\Facades\Route;

/*
 * 站点根路由（非 API）
 *
 * ⚠️ SEO 端点（`/sitemap.xml`、`/robots.txt`）**不在本文件**，见 `routes/seo.php`：
 * `routes/web.php` 的路由会自动挂上 `web` 中间件组，而该组会启动数据库会话；
 * 本项目是纯 API 后端（`SESSION_DRIVER=database` 但没有 sessions 表），挂上去会 500。
 *
 * 本文件目前只保留 Laravel 默认的欢迎页路由，未被任何前端使用。
 */
Route::get('/', function () {
    return view('welcome');
});
