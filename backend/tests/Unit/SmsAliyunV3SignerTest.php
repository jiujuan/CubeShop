<?php

use App\Support\Sms\AliyunV3Signer;

/*
 * 阿里云 V3 签名对拍（短信渠道计划 第一期）
 *
 * 这一组用例的价值在于：**向量来自阿里云官方文档，不是我自己算出来的**。
 * 签名错了只有在真机联调时才会暴露，而那时你只会收到一个 SignatureDoesNotMatch，
 * 无从判断是哪一步算错。把官方示例逐步钉住，任何一步被改坏都会立刻红。
 *
 * 官方假设值：AccessKeyId=YourAccessKeyId / Secret=YourAccessKeySecret /
 * nonce=3156853299f313e23d1673dc12e1703d / date=2023-10-26T10:22:32Z /
 * action=RunInstances / version=2014-05-26 / host=ecs.cn-shanghai.aliyuncs.com。
 */
test('TC-SMS-SIGN-001 规范化请求与官方文档示例逐字一致', function () {
    $canonical = AliyunV3Signer::canonicalRequest(
        method: 'POST',
        uri: '/',
        query: [
            'ImageId' => 'win2019_1809_x64_dtc_zh-cn_40G_alibase_20230811.vhd',
            'RegionId' => 'cn-shanghai',
        ],
        headers: [
            'host' => 'ecs.cn-shanghai.aliyuncs.com',
            'x-acs-action' => 'RunInstances',
            'x-acs-content-sha256' => hash('sha256', ''),
            'x-acs-date' => '2023-10-26T10:22:32Z',
            'x-acs-signature-nonce' => '3156853299f313e23d1673dc12e1703d',
            'x-acs-version' => '2014-05-26',
        ],
        payloadHash: hash('sha256', ''),
    );

    // 官方示例：HashedCanonicalRequest
    expect(hash('sha256', $canonical))
        ->toBe('7ea06492da5221eba5297e897ce16e55f964061054b7695beedaac1145b1e259');

    expect(AliyunV3Signer::stringToSign($canonical))
        ->toBe('ACS3-HMAC-SHA256'."\n".'7ea06492da5221eba5297e897ce16e55f964061054b7695beedaac1145b1e259');
});

test('TC-SMS-SIGN-002 签名值：HMAC 密钥是 AccessKey Secret 本身（不是 ACS3+Secret）', function () {
    $canonical = AliyunV3Signer::canonicalRequest(
        'POST',
        '/',
        ['ImageId' => 'win2019_1809_x64_dtc_zh-cn_40G_alibase_20230811.vhd', 'RegionId' => 'cn-shanghai'],
        [
            'host' => 'ecs.cn-shanghai.aliyuncs.com',
            'x-acs-action' => 'RunInstances',
            'x-acs-content-sha256' => hash('sha256', ''),
            'x-acs-date' => '2023-10-26T10:22:32Z',
            'x-acs-signature-nonce' => '3156853299f313e23d1673dc12e1703d',
            'x-acs-version' => '2014-05-26',
        ],
        hash('sha256', ''),
    );

    // 官方示例：Signature
    expect(AliyunV3Signer::signature(AliyunV3Signer::stringToSign($canonical), 'YourAccessKeySecret'))
        ->toBe('06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0');

    // 反例钉死：若哪天被改成 ACS3+Secret，这里会红（这是最容易记错的一点）
    expect(AliyunV3Signer::signature(AliyunV3Signer::stringToSign($canonical), 'ACS3YourAccessKeySecret'))
        ->not->toBe('06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0');
});

test('TC-SMS-SIGN-003 Authorization 头格式与已签名头列表', function () {
    $auth = AliyunV3Signer::authorization(
        accessKeyId: 'YourAccessKeyId',
        accessKeySecret: 'YourAccessKeySecret',
        method: 'POST',
        host: 'ecs.cn-shanghai.aliyuncs.com',
        query: ['RegionId' => 'cn-shanghai'],
        headers: [
            'X-ACS-Action' => 'RunInstances',
            'X-ACS-Version' => '2014-05-26',
        ],
        payloadHash: hash('sha256', ''),
    );

    // 头名大小写不影响结果（会被统一小写后排序）
    expect($auth)->toStartWith('ACS3-HMAC-SHA256 Credential=YourAccessKeyId,');
    expect($auth)->toContain(
        'SignedHeaders=host;x-acs-action;x-acs-version,',
    );
    expect($auth)->toMatch('/Signature=[0-9a-f]{64}$/');
});

test('TC-SMS-SIGN-004 查询串按参数名升序并做 RFC3986 编码（中文签名/JSON 参数）', function () {
    $query = AliyunV3Signer::canonicalQuery([
        'TemplateParam' => '{"code":"1234"}',
        'SignName' => '阿里云短信测试',
        'PhoneNumbers' => '13800138000',
    ]);

    // PhoneNumbers < SignName < TemplateParam（字母序）
    expect($query)->toStartWith('PhoneNumbers=13800138000&SignName=');
    expect($query)->toContain(rawurlencode('阿里云短信测试'));
    expect($query)->toContain(rawurlencode('{"code":"1234"}'));

    // 未编码的中文与花括号不应出现在规范化串里
    expect($query)->not->toContain('阿里云');
    expect($query)->not->toContain('{');
});

test('TC-SMS-SIGN-005 规范化头：小写键升序、值两端去空格、每行以换行结尾', function () {
    $headers = AliyunV3Signer::canonicalHeaders([
        'X-ACS-Version' => ' 2017-05-25 ',
        'Host' => 'dysmsapi.aliyuncs.com',
        'X-ACS-Date' => '2026-09-25T00:00:00Z',
    ]);

    expect($headers)->toBe(
        "host:dysmsapi.aliyuncs.com\n"
        ."x-acs-date:2026-09-25T00:00:00Z\n"
        ."x-acs-version:2017-05-25\n"
    );
});
