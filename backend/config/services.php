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

    /*
    |--------------------------------------------------------------------------
    | 电子面单申请渠道（出单侧，与轨迹查询 shipping 平行，V1.2）
    |--------------------------------------------------------------------------
    |
    | channel：留空 = 降级（NullWaybillChannel，发货回落手动录入）；mock = 本地演示；
    | kuaidi100 等真实渠道实现 WaybillChannelInterface 后在此切换。
    | 密钥复用 SHIPPING_CHANNEL_KEY/CUSTOMER（同一快递100 账号，凭证不入库）。
    | 后台可用 system_configs.waybill.channel 覆盖（与 shipping.channel 同机制）。
    |
    */
    'waybill' => [
        'channel' => env('WAYBILL_CHANNEL'),
        'key' => env('SHIPPING_CHANNEL_KEY'),
        'customer' => env('SHIPPING_CHANNEL_CUSTOMER'),
        'order_url' => env('WAYBILL_ORDER_URL', 'https://poll.kuaidi100.com/poll/order.do'),
        'timeout' => (int) env('WAYBILL_TIMEOUT', 8),
        'default_weight_gram' => (int) env('WAYBILL_DEFAULT_WEIGHT_GRAM', 1000),
        'sender_name' => env('WAYBILL_SENDER_NAME', 'CubeShop 仓'),
        'sender_phone' => env('WAYBILL_SENDER_PHONE', ''),
        'sender_address' => env('WAYBILL_SENDER_ADDRESS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | 站内搜索（V1.2 S1）
    |--------------------------------------------------------------------------
    |
    | 阶段一走 PG 原生全文检索（`products.search_vector` 生成列 + GIN），
    | 阶段二换 Meilisearch/ES 时只换引擎实现，这里的开关不动。
    |
    | index_taxonomy_names：品牌名/分类名是否计入索引。默认开 —— 搜「小米」「沙发」
    | 这类词时品牌/分类是最强召回信号；关掉后改品牌/分类名不再需要级联重算商品。
    |
    | engine：留空 = 自动（PG 全文检索，不可用时降级 LIKE）；`off`/`like` = 强制走 LIKE 降级。
    | 阶段二 `meilisearch` / `elasticsearch` 在此切换，业务代码不动。
    |
    | 其余键（fallback_enabled / cache_ttl）随 S1-09 一起接入，
    | 并由 system_configs.search.* 在运行时覆写（与 shipping.channel 同机制）。
    |
    */
    'search' => [
        'engine' => env('SEARCH_ENGINE'),
        'index_taxonomy_names' => (bool) env('SEARCH_INDEX_TAXONOMY_NAMES', true),
        // 联想接口防刷：同 IP 每分钟次数（RateLimiter 'search-suggest'，AppServiceProvider 注册）。
        // 只走 env 不进 system_configs：联想是机器流量高频打点，改频率属运维操作而非运营配置。
        'suggest_rate_limit' => max(1, (int) env('SEARCH_SUGGEST_RATE_LIMIT', 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | 短信渠道（短信渠道计划 第一期）
    |--------------------------------------------------------------------------
    |
    | 只放**运维参数**：超时与发送限流。凭证（AccessKey）密文存在 sms_configs 表，
    | 运营开关（sms.enabled / sms.code_scenes）存在 system_configs，均不在此处。
    |
    | send_rate_limit：同 IP 每分钟发送次数（RateLimiter 'sms-send'）。短信是按条计费的，
    | 一个未限流的发送口等于一个可被刷的账单。
    |
    */
    'sms' => [
        'timeout' => (int) env('SMS_TIMEOUT', 5),
        'send_rate_limit' => max(1, (int) env('SMS_SEND_RATE_LIMIT', 5)),
    ],

    /*
    |--------------------------------------------------------------------------
    | 认证相关限流
    |--------------------------------------------------------------------------
    |
    | 全部走 config() 读取（config 缓存后是纯数组取用，无 DB / 缓存查询，不增加运行时开销）。
    | 验证码图片是「点一下刷新一次」的高频轻接口，原先与登录注册共用一组额度，正常操作
    | 就会撞 429，因此单独列出一个更宽松的限流器。
    |
    */
    'auth' => [
        // 认证组兜底：只防洪水，防暴破交给下面按账号维度的 login / auth-register
        'rate_limit' => max(1, (int) env('AUTH_RATE_LIMIT', 10)),
        'captcha_rate_limit' => max(1, (int) env('CAPTCHA_RATE_LIMIT', 60)),
        'login_rate_limit' => max(1, (int) env('LOGIN_RATE_LIMIT', 5)),
        'register_rate_limit' => max(1, (int) env('REGISTER_RATE_LIMIT', 5)),
    ],

];
