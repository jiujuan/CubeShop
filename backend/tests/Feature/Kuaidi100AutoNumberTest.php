<?php

use App\Models\ExpressCompany;
use App\Support\Shipping\Kuaidi100AutoNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * V1.1 三期：快递100 智能单号识别
 *
 * 覆盖：① 识别成功并映射为内部编码；② 字典未收录的候选被过滤（不误导）；
 *       ③ 未配置/关闭开关/错误码/网络异常一律降级为空数组；
 *       ④ topCode 取相似度最高的候选。
 *
 * ⚠️ 官方不保证 100% 准确，本能力只做提示与校验，绝不可自动改写商家录入。
 */

function kd100AutoCompanies(): void
{
    foreach ([
        ['YTO', '圆通速递', 'yuantong'],
        ['ZTO', '中通快递', 'zhongtong'],
        ['SF', '顺丰速运', 'shunfeng'],
    ] as [$code, $name, $channel]) {
        ExpressCompany::forceCreate([
            'code' => $code,
            'name' => $name,
            'channel_code' => $channel,
            'sort' => 1,
            'status' => 1,
        ]);
    }
    Kuaidi100AutoNumber::flushCache();
}

beforeEach(function () {
    config([
        'services.shipping.key' => 'TESTKEY',
        'services.shipping.autonumber_enabled' => true,
        'services.shipping.autonumber_url' => 'https://www.kuaidi100.com/autonumber/auto',
    ]);
    kd100AutoCompanies();
});

test('TC-KD100-10 识别成功返回内部编码候选', function () {
    Http::fake(['*' => Http::response([
        ['lengthPre' => 15, 'comCode' => 'yuantong', 'name' => '圆通速递'],
        ['lengthPre' => 15, 'comCode' => 'zhongtong', 'name' => '中通快递'],
    ], 200)]);

    $result = (new Kuaidi100AutoNumber)->detect('YT1234567890123');

    expect($result)->toBe([
        ['code' => 'YTO', 'name' => '圆通速递'],
        ['code' => 'ZTO', 'name' => '中通快递'],
    ]);

    Http::assertSent(fn ($request) => ($request->data()['num'] ?? null) === 'YT1234567890123'
        && ($request->data()['key'] ?? null) === 'TESTKEY');
});

test('TC-KD100-11 字典未收录的候选被过滤', function () {
    Http::fake(['*' => Http::response([
        ['comCode' => 'youzhengguonei', 'name' => '邮政快递'], // 本平台未启用
        ['comCode' => 'shunfeng', 'name' => '顺丰速运'],
    ], 200)]);

    $result = (new Kuaidi100AutoNumber)->detect('SF1234567890');

    // 只保留字典内且启用的公司，避免推荐商家用不了的选项
    expect($result)->toBe([['code' => 'SF', 'name' => '顺丰速运']]);
});

test('TC-KD100-12 各种不可用场景一律降级为空数组', function () {
    // 未配置 key
    config(['services.shipping.key' => null]);
    expect((new Kuaidi100AutoNumber)->available())->toBeFalse()
        ->and((new Kuaidi100AutoNumber)->detect('YT1234567890123'))->toBe([]);

    // 开关关闭
    config(['services.shipping.key' => 'TESTKEY', 'services.shipping.autonumber_enabled' => false]);
    expect((new Kuaidi100AutoNumber)->available())->toBeFalse();

    // 错误码分支（key 过期 = 未开通识别）
    config(['services.shipping.autonumber_enabled' => true]);
    Http::fake(['*' => Http::response(['returnCode' => '601', 'message' => 'key过期', 'result' => false], 200)]);
    expect((new Kuaidi100AutoNumber)->detect('YT1234567890123'))->toBe([]);

    // HTTP 失败
    Http::fake(['*' => Http::response('error', 500)]);
    expect((new Kuaidi100AutoNumber)->detect('YT1234567890123'))->toBe([]);

    // 网络异常
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
    expect((new Kuaidi100AutoNumber)->detect('YT1234567890123'))->toBe([]);

    // 空单号不请求
    expect((new Kuaidi100AutoNumber)->detect(''))->toBe([]);
});

test('TC-KD100-13 topCode 取相似度最高的候选', function () {
    // ⚠️ 同测试内多次 Http::fake 只有首个 stub 生效，必须用 sequence 区分
    Http::fake([
        '*' => Http::sequence()
            ->push([
                ['comCode' => 'shunfeng', 'name' => '顺丰速运', 'noCount' => 302236],
                ['comCode' => 'yuantong', 'name' => '圆通速递', 'noCount' => 24],
            ], 200)
            ->push([], 200),
    ]);

    // noCount 最高的排最前，topCode 取首个
    expect((new Kuaidi100AutoNumber)->topCode('SF1234567890'))->toBe('SF');

    // 无法识别时返回 null，调用方据此跳过提示
    expect((new Kuaidi100AutoNumber)->topCode('XXXXXXXX'))->toBeNull();
});
