<?php

use App\Services\Wms\Support\PayloadMasker;

/**
 * 报文脱敏（WMS 计划 P2 / F8、Step 3）
 *
 * 目标：`wms_api_logs` 里永远搜不到 AppSecret / access_token / 完整手机号，
 * 但单号、商品编码、数量、错误码原样保留（排障够用）。
 */

test('凭证类键名整值抹除，键名归一后 app_secret/appSecret/app.secret 都能命中', function () {
    $masker = new PayloadMasker;

    $out = $masker->mask([
        'app_secret' => 'SUPER_SECRET_VALUE',
        'appSecret' => 'ALSO_SECRET',
        'app.secret' => 'DOT_SECRET',
        'access_token' => 'TOKEN_XYZ',
        'Password' => 'p@ss',
    ]);

    expect($out['app_secret'])->toBe('***')
        ->and($out['appSecret'])->toBe('***')
        ->and($out['app.secret'])->toBe('***')
        ->and($out['access_token'])->toBe('***')
        ->and($out['Password'])->toBe('***');

    // 明文不得出现在任何位置
    expect(json_encode($out, JSON_UNESCAPED_UNICODE))
        ->not->toContain('SUPER_SECRET_VALUE')
        ->not->toContain('TOKEN_XYZ');
});

test('联系方式保留后 4 位，过短整串抹除', function () {
    $masker = new PayloadMasker;

    $out = $masker->mask([
        'mobile' => '13800008000',
        'phone' => '021-8888',
        'telephone' => '123',        // 长度 <= 保留位数 → 全星号
        'contact_mobile' => '13900001234',
    ]);

    expect($out['mobile'])->toBe('*******8000')
        ->and($out['contact_mobile'])->toBe('*******1234')
        ->and($out['phone'])->toBe('****8888')   // "021-8888" 共 8 位，保留后 4
        ->and($out['telephone'])->toBe('***');
});

test('递归下钻数组任意深度，普通字段原样保留', function () {
    $masker = new PayloadMasker;

    $out = $masker->mask([
        'deliveryOrderCode' => 'FO20260920000001',
        'receiverInfo' => [
            'name' => '张三',
            'mobile' => '13800008000',
            'detailAddress' => '文一西路 1 号',
        ],
        'orderLines' => [
            ['itemCode' => 'W-SKU-001', 'planQty' => 2],
            ['itemCode' => 'W-SKU-002', 'planQty' => 1, 'appSecret' => 'LEAK'],
        ],
    ]);

    // 收件人手机号脱敏，但姓名 / 单号 / 商品编码 / 数量原样
    expect($out['receiverInfo']['mobile'])->toBe('*******8000')
        ->and($out['receiverInfo']['name'])->toBe('张三')
        ->and($out['deliveryOrderCode'])->toBe('FO20260920000001')
        ->and($out['orderLines'][0]['itemCode'])->toBe('W-SKU-001')
        ->and($out['orderLines'][0]['planQty'])->toBe(2)
        // 深层数组里的凭证同样被抹除
        ->and($out['orderLines'][1]['appSecret'])->toBe('***');
});

test('app_key 与 sign 刻意不掩，空手机号不产生噪音占位', function () {
    $masker = new PayloadMasker;

    $out = $masker->mask([
        'app_key' => '1234567',
        'sign' => 'ABCDEF0123456789',
        'mobile' => '',
    ]);

    expect($out['app_key'])->toBe('1234567')          // 用于辨识应用，排障要看
        ->and($out['sign'])->toBe('ABCDEF0123456789') // 签名非凭证
        ->and($out['mobile'])->toBe('');              // 空值不变成 ***
});

test('可注入自定义规则（不依赖 config）', function () {
    $masker = new PayloadMasker([
        'keywords' => ['token'],
        'phone_keys' => ['tel'],
        'phone_keep_tail' => 2,
    ]);

    $out = $masker->mask([
        'api_token' => 'secret-token',
        'app_secret' => 'not-matched-now',   // 未在自定义 keywords 内 → 保留
        'tel' => '13800008000',
    ]);

    expect($out['api_token'])->toBe('***')
        ->and($out['app_secret'])->toBe('not-matched-now')
        ->and($out['tel'])->toBe('*********00'); // 11 位保留后 2
});
