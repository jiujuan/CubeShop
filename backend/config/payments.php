<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 支付配置（Roadmap P5）
    |--------------------------------------------------------------------------
    | sandbox：本地/开发环境启用沙箱（/payments/sandbox/{no} 模拟渠道通知）
    |          安全约束（SEC-01）：默认 false，且仅在 local/testing/staging 环境生效。
    |          生产环境必须省略该变量或显式设为 false——配置被误设为 true 也不会放行，
    |          因为 PaymentService::sandboxEnabled() 另有环境白名单兜底。
    |
    | secret：回调验签密钥（SEC-02）：**不提供任何默认值**。
    |         生产环境必须通过环境变量 PAY_SIGN_SECRET 配置强随机值
    |         （生成：php -r "echo bin2hex(random_bytes(32));"）。
    |         未配置时非 local 环境由 AppServiceProvider 启动 fail-fast，避免「空密钥可伪造回调」。
    */
    'sandbox' => env('PAYMENT_SANDBOX', false),
    'secret' => env('PAY_SIGN_SECRET'),

    /*
     | callback_allowed_ips：回调来源 IP 白名单（SEC-11）
     | 仅对**非沙箱**渠道（微信/支付宝等真实网关）生效；为空数组表示不限制（依赖签名+nonce 兜底）。
     | 生产建议填入网关出口网段，例如 ['10.0.0.0/8', '140.207.0.0/16']，
     | 即使签名密钥泄露，也能挡住来自任意公网 IP 的伪造回调。
     | 注意：使用 CIDR 匹配；单个 IP 可写 '1.2.3.4'。
     */
    'callback_allowed_ips' => env('PAYMENT_CALLBACK_ALLOWED_IPS'),
];
