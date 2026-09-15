<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 支付配置（Roadmap P5）
    |--------------------------------------------------------------------------
    | sandbox：本地/开发环境启用沙箱（/payments/sandbox/{no} 模拟渠道通知）
    | secret：回调验签密钥（生产环境必须通过环境变量 PAY_SIGN_SECRET 配置强随机值）
    */
    'sandbox' => env('PAYMENT_SANDBOX', true),
    'secret' => env('PAY_SIGN_SECRET', 'cubeshop-sandbox-secret'),
];
