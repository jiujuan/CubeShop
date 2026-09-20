<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 内容中心 CMS（二期 CMS-202）
    |--------------------------------------------------------------------------
    |
    | `site_url` 是**前台站点**（用户访问的域名），不是 API 域名。
    | sitemap.xml 由后端产出（数据在后端，无需构建期预渲染），但里面列的 URL
    | 必须指向前台——两者在部署上通常不同源，所以单独配置而不是复用 APP_URL。
    |
    */

    'site_url' => rtrim((string) env('CMS_SITE_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
    | 站点地图中的静态核心页（相对路径 ⇒ 拼到 site_url 之后）。
    | 不含商品/分类：那两类量级大，应走 sitemap index + 分片（见计划文档 §7 遗留）。
    */
    'static_pages' => [
        '/',
        '/service-center/faq',
        '/announcements',
        '/news',
        '/coupons/center',
    ],

];
