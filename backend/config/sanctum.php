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
    /*
    |--------------------------------------------------------------------------
    | Token 有效期（SEC-06）
    |--------------------------------------------------------------------------
    |
    | 单位是**分钟**（不是秒）。留空表示永不过期——Token 一旦泄露（日志、代理、
    | 共享电脑、被投毒的前端依赖）将长期可用，且服务端无从吊销感知。
    |
    | 取值权衡：评审文档建议 12 小时，但本项目尚无前端「无感续期」链路，
    | 12 小时会让买家在浏览/下单途中被登出，反而促使用户反复登录、抬高密码暴露面；
    | 因此默认取 7 天（10080 分钟），并配套提供：
    |   - POST /auth/refresh  轮换 Token（短有效期场景可无感续期）
    |   - GET  /auth/devices  最近登录设备
    |   - DELETE /auth/devices/{id}  踢下线
    | 若前端完成续期改造，可将此值下调至 720（12 小时）以进一步收紧泄露窗口。
    |
    */
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 10080),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
