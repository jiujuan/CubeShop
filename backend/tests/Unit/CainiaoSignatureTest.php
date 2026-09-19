<?php

use App\Services\Wms\Adapters\Cainiao\Signature;

/**
 * 奇门签名（WMS 计划 P2 / F2、Step 2）
 *
 * 不 mock 任何东西：待签串与摘要都用**独立于实现的方式**再算一遍
 * （显式拼串 + 直接调 `md5()` / `hash_hmac()`），所以这不是自证——
 * 实现如果把排序、空值、secret 位置改错，这里会红。
 */

test('待签串：参数按 ASCII 升序拼接，空值整项跳过，0 保留', function () {
    $sig = new Signature;

    $source = $sig->buildSource([
        'method' => 'taobao.qimen.deliveryorder.create',
        'app_key' => '1234567',
        'timestamp' => '2026-09-20 10:00:00',
        'customerId' => '',        // 空 → 跳过
        'format' => 'json',
        'v' => '2.0',
        'zero' => '0',             // "0" 非空 → 保留（关键边界）
        'nil' => null,             // null → 跳过
        'flag' => false,           // false → 跳过
    ]);

    // 期望串：仅非空参数，按 key 升序（ASCII：a < c < f < m < t < v < z）
    $expected = 'app_key1234567'
        .'formatjson'
        .'methodtaobao.qimen.deliveryorder.create'
        .'timestamp2026-09-20 10:00:00'
        .'v2.0'
        .'zero0';

    expect($source)->toBe($expected);
});

test('待签串：业务报文 body 紧跟在参数串之后，sign 自身被排除', function () {
    $sig = new Signature;

    $source = $sig->buildSource(
        ['app_key' => 'abc', 'sign' => 'SHOULD_BE_IGNORED', 'format' => 'json'],
        '{"a":1}',
    );

    expect($source)->toBe('app_keyabcformatjson{"a":1}')
        ->and($source)->not->toContain('SHOULD_BE_IGNORED');
});

test('md5 默认口径：secret 首尾都包，结果为大写十六进制', function () {
    $sig = new Signature;
    $params = ['app_key' => 'testapp', 'method' => 'taobao.qimen.inventory.query'];
    $secret = 'my-secret';
    $body = '{"warehouseCode":"WH1"}';

    $source = $sig->buildSource($params, $body);
    $expected = strtoupper(md5($secret.$source.$secret));

    expect($sig->sign($params, $secret, $body))->toBe($expected)
        ->and($expected)->toMatch('/^[0-9A-F]{32}$/');
});

test('md5 tail 口径：secret 只包尾（TOP 通用 SDK 变体）', function () {
    $sig = new Signature;
    $params = ['app_key' => 'testapp', 'method' => 'taobao.qimen.inventory.query'];
    $secret = 'my-secret';
    $body = '{"warehouseCode":"WH1"}';

    $source = $sig->buildSource($params, $body);
    $expected = strtoupper(md5($source.$secret));

    $tail = $sig->sign($params, $secret, $body, Signature::METHOD_MD5, Signature::WRAP_TAIL);

    expect($tail)->toBe($expected)
        // 两种口径必须产出不同签名，否则配置项失去意义
        ->and($tail)->not->toBe($sig->sign($params, $secret, $body, Signature::METHOD_MD5, Signature::WRAP_BOTH));
});

test('hmac_md5 口径：secret 作 HMAC key，不参与待签串拼接', function () {
    $sig = new Signature;
    $params = ['app_key' => 'testapp'];
    $secret = 'my-secret';
    $body = '{}';

    $source = $sig->buildSource($params, $body);
    $expected = strtoupper(hash_hmac('md5', $source, $secret));

    // wrap 参数对 hmac 无效：both / tail 结果一致
    expect($sig->sign($params, $secret, $body, Signature::METHOD_HMAC_MD5))->toBe($expected)
        ->and($sig->sign($params, $secret, $body, Signature::METHOD_HMAC_MD5, Signature::WRAP_TAIL))->toBe($expected);
});

test('verify：正确签名通过，篡改任意一位即失败，空签名直接拒绝', function () {
    $sig = new Signature;
    $params = ['app_key' => 'testapp', 'method' => 'taobao.qimen.deliveryorder.create'];
    $secret = 'my-secret';
    $body = '{"k":"v"}';

    $good = $sig->sign($params, $secret, $body);

    expect($sig->verify($params, $secret, $good, $body))->toBeTrue()
        ->and($sig->verify($params, $secret, strtolower($good), $body))->toBeTrue()   // 大小写不敏感
        ->and($sig->verify($params, $secret, substr($good, 0, 31).'0', $body))->toBeFalse()
        ->and($sig->verify($params, 'wrong-secret', $good, $body))->toBeFalse()
        ->and($sig->verify($params, $secret, '', $body))->toBeFalse();
});

test('签名与是否含中文/斜杠的 body 逐字节一致相关', function () {
    $sig = new Signature;
    $params = ['app_key' => 'testapp'];
    $secret = 's';

    $a = $sig->sign($params, $secret, '{"name":"张三"}');
    $b = $sig->sign($params, $secret, '{"name":"\u5f20\u4e09"}');   // 转义写法同一语义、不同字节

    // 官方要求「签名用原文、发送也用原文」：字节不同即签名不同，正是要守住的性质
    expect($a)->not->toBe($b);
});
