<?php

// 跨域配置（API 文档 11.2 / Roadmap P7）
// 生产环境务必设置 CORS_ALLOWED_ORIGINS 环境变量（逗号分隔），
// 例如：CORS_ALLOWED_ORIGINS=https://www.cubeshop.com,https://admin.cubeshop.com
// 默认 '*' 仅用于本地开发。

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => (static function (): array {
        $raw = (string) env('CORS_ALLOWED_ORIGINS', '*');
        $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $origins === [] ? ['*'] : $origins;
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
