<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WMS 对接配置（WMS 计划 P2 / F1）
    |--------------------------------------------------------------------------
    | 只放「与租户无关」的协议级参数。**密钥一律不落这里**——AppKey / AppSecret /
    | 货主编码 / 仓库编码都属于某个仓库的对接配置，存在 `wms_configs`
    | （AppSecret 由模型 Crypt 加密），本文件只描述「怎么连」而非「连谁」。
    |
    | SEC-01 约定：网关地址等敏感 env **不提供默认值**。缺失时由
    | CainiaoGateway 在**调用时** fail-closed 抛错，而不是启动就崩——
    | WMS 是可选能力，未配置的项目不该无法启动；但一旦配了真实凭证却缺网关，
    | 也绝不能静默返回假成功。
    */
    'providers' => [
        'cainiao' => [
            // 奇门网关（生产 / 沙箱各一）。env 无默认值：未配置即视为「未接入真实网关」
            'gateway' => [
                'prod' => env('WMS_CAINIAO_GATEWAY_PROD'),
                'sandbox' => env('WMS_CAINIAO_GATEWAY_SANDBOX'),
            ],

            // 网络超时（秒）；写接口不做 HTTP 层重试，重试交给 Job（见 PushOutboundJob）
            'timeout' => (int) env('WMS_CAINIAO_TIMEOUT', 15),
            'connect_timeout' => (int) env('WMS_CAINIAO_CONNECT_TIMEOUT', 5),

            // 奇门协议版本与签名方式（以官方最新文档为准；改版时只调这里）
            'version' => env('WMS_CAINIAO_VERSION', '2.0'),
            // 报文里的 sign_method 字段值：md5 | hmac_md5
            'sign_method' => env('WMS_CAINIAO_SIGN_METHOD', 'md5'),

            /*
             | ⚠️ 签名待签串里 secret 的包裹位置——**这是 P2 唯一无法离线验证的细节**。
             |
             | 奇门官方文档给的是 `md5(secret + 参数串 + body + secret)`（首尾都包），
             | 而淘宝开放平台通用 SDK `signTopRequest` 的 md5 分支只有尾部追加
             | （`md5(参数串 + body + secret)`）。两者历史上都出现过，取决于网关形态。
             |
             | 默认 `both`（对齐奇门文档，也是本计划采用的口径）；联调若报
             | 「签名验证失败 / error_code:25」，把它改成 `tail` 再试一次即可，
             | 不需要改代码。`hmac_md5` 不受本项影响（secret 作 HMAC key）。
             */
            'sign_secret_wrap' => env('WMS_CAINIAO_SIGN_SECRET_WRAP', 'both'),

            /*
             | 奇门方法名（含命名空间前缀，随网关部署形态不同）。
             | 淘宝/阿里云奇门形如 `taobao.qimen.xxx`；部分自建网关只认 `xxx`，
             | 若联调时对方要求去前缀，改这里即可，无需改代码。
             */
            'methods' => [
                'create_outbound' => env('WMS_CAINIAO_METHOD_CREATE_OUTBOUND', 'taobao.qimen.deliveryorder.create'),
                'cancel_outbound' => env('WMS_CAINIAO_METHOD_CANCEL_OUTBOUND', 'taobao.qimen.deliveryorder.cancel'),
                'create_return_inbound' => env('WMS_CAINIAO_METHOD_CREATE_RETURN', 'taobao.qimen.returnorder.create'),
                'cancel_return_inbound' => env('WMS_CAINIAO_METHOD_CANCEL_RETURN', 'taobao.qimen.returnorder.cancel'),
                'query_inventory' => env('WMS_CAINIAO_METHOD_QUERY_INVENTORY', 'taobao.qimen.inventory.query'),
                // P3：单据状态主动查询（回调丢失补偿）
                'query_outbound' => env('WMS_CAINIAO_METHOD_QUERY_OUTBOUND', 'taobao.qimen.deliveryorder.query'),
            ],

            /*
             | 出库单类型：JYCK = 一般交易出库（设计文档 §7.1）。
             | 其他常见值：B2BCK（B2B 出库）/ THCKY（退货出库）等，按需扩展。
             */
            'order_type' => env('WMS_CAINIAO_ORDER_TYPE', 'JYCK'),

            /*
             | 来源平台编码（设计文档 §7.1「建议填 OTHER 或自有平台编码」）。
             | 留空时用 default_source_platform_code。
             */
            'source_platform_code' => env('WMS_CAINIAO_SOURCE_PLATFORM_CODE'),
            'default_source_platform_code' => 'OTHER',

            /*
             | 幂等判定：菜鸟对「单据已存在」的返回码集合（跨环境差异较大，
             | 配置化便于联调时按实际报文增补，不必改代码）。
             */
            'duplicate_codes' => [
                'S07',                        // 奇门常见：单据已存在
                'ORDER_ALREADY_EXISTS',
                'DELIVERY_ORDER_EXISTS',
            ],

            /*
             | 可重试判定：除「HTTP 408/429/5xx/网络异常」与通用系统码
             | （见 CainiaoErrorCode::DEFAULT_RETRYABLE_CODES）之外，额外视作可重试的业务码。
             |
             | ⚠️ `S0x` 各家网关含义不一致，默认**不**把所有 S0x 当可重试——尤其 S03（参数错）
             | 与 S04（签名错）任何口径下都重试无用。联调若发现某码确属对方临时故障，填这里即可。
             | 例：`WMS_CAINIAO_RETRYABLE_CODES=S03,S09`（逗号分隔）。
             */
            'retryable_codes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WMS_CAINIAO_RETRYABLE_CODES', '')),
            ))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 日志脱敏（F8）
    |--------------------------------------------------------------------------
    | 两层规则，都在 `PayloadMasker` 里递归生效（键名比较会忽略 `_`/`-`/`.`/空格）：
    | - `keywords`：命中即整值抹为 `***`。**凭证类字段永不入日志**；
    | - `phone_keys`：命中即「保留后 N 位」。手机号/联系方式。
    |
    | 注意：**故意不掩 `app_key`**——它标识「用了哪个应用」，排障时需要看；
    | 也故意不掩 `sign`——签名不是凭证，且 `design*` 之类键名会被误伤。
    */
    /*
    |--------------------------------------------------------------------------
    | 回调接收（WMS 计划 P3 / Step 2）
    |--------------------------------------------------------------------------
    */
    'callback' => [
        // 允许的 provider（路由白名单，未知 provider 直接拒绝）
        'providers' => ['cainiao'],

        /*
         | 回调来源 IP 白名单（逗号分隔 env）。
         | **空 = 不限制**（沙箱联调时对方出口 IP 不固定）；生产环境强烈建议配置奇门网关出口段。
         | 注意：白名单是纵深防御的第二层，第一层永远是签名验签——不可只配 IP 不验签。
         */
        'ip_whitelist' => array_values(array_filter(explode(',', (string) env('WMS_CALLBACK_IP_WHITELIST', '')))),

        // 同一条原始报文的防重放窗口（秒）：窗口内重复推送直接按 success 吞掉
        'replay_ttl' => (int) env('WMS_CALLBACK_REPLAY_TTL', 600),

        // wms_callback_dedups 清理保留天数（wms:prune-callbacks 调度用）
        'prune_days' => (int) env('WMS_CALLBACK_PRUNE_DAYS', 90),
    ],

    'mask' => [
        'keywords' => [
            'appsecret', 'secret', 'accesstoken', 'refreshtoken',
            'password', 'passwd', 'privatekey', 'credential',
        ],
        'phone_keys' => [
            'mobile', 'phone', 'telephone', 'tel',
        ],
        // 联系方式脱敏：保留后 N 位
        'phone_keep_tail' => 4,
    ],
];
