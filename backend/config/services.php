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
        // 实时查询（快递100 开放平台）；智能识别与查询同源，可单独指向内网代理
        'query_url' => env('SHIPPING_QUERY_URL', 'https://poll.kuaidi100.com/poll/query.do'),
        'autonumber_url' => env('SHIPPING_AUTONUMBER_URL', 'https://www.kuaidi100.com/autonumber/auto'),
        // 智能识别默认开启；随查询套餐赠送，失败一律降级不阻断发货
        'autonumber_enabled' => (bool) env('SHIPPING_AUTONUMBER_ENABLED', true),
        // 批量校验时最多识别多少行（超出的行跳过识别，避免大文件拖慢导入）
        'autonumber_batch_limit' => (int) env('SHIPPING_AUTONUMBER_BATCH_LIMIT', 100),
        'timeout' => (int) env('SHIPPING_TIMEOUT', 8),
        'pull_window_days' => (int) env('SHIPPING_PULL_WINDOW_DAYS', 30),
        'batch_size' => (int) env('SHIPPING_BATCH_SIZE', 50),
        'batch_delay_ms' => (int) env('SHIPPING_BATCH_DELAY_MS', 200),
        'max_failures' => (int) env('SHIPPING_MAX_FAILURES', 5),
    ],

];
