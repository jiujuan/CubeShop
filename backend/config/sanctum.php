<?php

// Sanctum 配置（Roadmap P7：Token 过期联调）
// SANCTUM_TOKEN_EXPIRATION：Token 有效期（分钟），留空表示永不过期。
// 生产建议设置（如 10080 = 7 天）；前端已处理 401 → 跳转登录。

use Laravel\Sanctum\Sanctum;

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,localhost:8000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    'guard' => ['web'],

    // null = 永不过期；生产建议设置分钟数（如 10080 = 7 天）
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', null),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
