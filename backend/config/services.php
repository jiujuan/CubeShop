<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 物流轨迹查询渠道（V1.1 T-045）
    |--------------------------------------------------------------------------
    |
    | channel：留空 = 降级（NullChannel，Job 跳过）；mock = 本地演示；
    | kuaidi100 等真实渠道实现 ShippingChannelInterface 后在此切换。
    | 密钥仅存 .env，严禁入库/入代码库。
    |
    */
    'shipping' => [
        'channel' => env('SHIPPING_CHANNEL'),
        'key' => env('SHIPPING_CHANNEL_KEY'),
        'customer' => env('SHIPPING_CHANNEL_CUSTOMER'),
        'pull_window_days' => (int) env('SHIPPING_PULL_WINDOW_DAYS', 30),
        'batch_size' => (int) env('SHIPPING_BATCH_SIZE', 50),
        'batch_delay_ms' => (int) env('SHIPPING_BATCH_DELAY_MS', 200),
        'max_failures' => (int) env('SHIPPING_MAX_FAILURES', 5),
    ],

];
