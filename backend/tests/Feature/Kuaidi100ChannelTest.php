<?php

use App\Models\ExpressCompany;
use App\Support\Shipping\Kuaidi100Channel;
use App\Support\Shipping\TraceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * V1.1 三期：快递100 实时查询渠道
 *
 * 覆盖：① 密钥缺失不可用；② 签名与请求参数符合官方规范；③ 内部编码→渠道编码转换；
 *       ④ 顺丰/中通缺手机号直接失败；⑤ 电商虚拟号取「-」后四位；⑥ 轨迹文案→阶段映射；
 *       ⑦ 运单级 state 兜底；⑧ 业务失败与网络异常收敛为 TraceResult::fail。
 */

/** 快递公司字典（内部 code → 快递100 channel_code） */
function kd100Companies(): void
{
    foreach ([
        ['SF', '顺丰速运', 'shunfeng'],
        ['ZTO', '中通快递', 'zhongtong'],
        ['YTO', '圆通速递', 'yuantong'],
        ['JD', '京东物流', 'jd'],
    ] as [$code, $name, $channel]) {
        ExpressCompany::forceCreate([
            'code' => $code,
            'name' => $name,
            'channel_code' => $channel,
            'sort' => 1,
            'status' => 1,
        ]);
    }
    Kuaidi100Channel::flushCache();
}

/** 快递100 成功响应体 */
function kd100Body(string $state, array $data): array
{
    return ['message' => 'ok', 'status' => '200', 'state' => $state, 'com' => 'yuantong', 'nu' => 'YT1', 'data' => $data];
}

function kd100Trace(string $context, string $time = '2026-09-01 10:00:00'): array
{
    return ['context' => $context, 'time' => $time, 'ftime' => $time];
}

beforeEach(function () {
    config([
        'services.shipping.key' => 'TESTKEY',
        'services.shipping.customer' => 'TESTCUSTOMER',
        'services.shipping.query_url' => 'https://poll.kuaidi100.com/poll/query.do',
    ]);
    kd100Companies();
});

test('TC-KD100-01 密钥缺失时渠道不可用', function () {
    config(['services.shipping.key' => null]);
    expect((new Kuaidi100Channel)->available())->toBeFalse();

    config(['services.shipping.key' => 'TESTKEY', 'services.shipping.customer' => null]);
    expect((new Kuaidi100Channel)->available())->toBeFalse();

    config(['services.shipping.customer' => 'TESTCUSTOMER']);
    expect((new Kuaidi100Channel)->available())->toBeTrue();
});

test('TC-KD100-02 签名与请求参数符合官方规范', function () {
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);

    (new Kuaidi100Channel)->query('YTO', 'YT8888888', '13800001111');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'poll.kuaidi100.com/poll/query.do')) {
            return false;
        }
        $body = $request->data();
        $param = (string) ($body['param'] ?? '');
        $paramArr = json_decode($param, true);

        return ($body['customer'] ?? null) === 'TESTCUSTOMER'
            // sign = strtoupper(md5(param + key + customer))，param 为未 urlencode 的 JSON
            && ($body['sign'] ?? null) === strtoupper(md5($param.'TESTKEY'.'TESTCUSTOMER'))
            && ($paramArr['com'] ?? null) === 'yuantong'
            && ($paramArr['num'] ?? null) === 'YT8888888'
            && ($paramArr['phone'] ?? null) === '13800001111'
            && ($paramArr['order'] ?? null) === 'asc';
    });
});

test('TC-KD100-03 内部编码转换为渠道编码，字典缺失时回落', function () {
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);

    (new Kuaidi100Channel)->query('JD', 'JD12345678');

    Http::assertSent(fn ($request) => json_decode((string) $request->data()['param'], true)['com'] === 'jd');

    // 字典未收录：回落内部 code 本身（SF 与快递100 部分重合，总比直接失败好）
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);
    (new Kuaidi100Channel)->query('EMS', 'EMS12345678');
    Http::assertSent(fn ($request) => json_decode((string) $request->data()['param'], true)['com'] === 'EMS');

    // 空编码直接失败，不发请求
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);
    $result = (new Kuaidi100Channel)->query('', 'X12345678');
    expect($result->success)->toBeFalse()->and($result->message)->toContain('未配置渠道编码');
});

test('TC-KD100-04 顺丰与中通缺失手机号直接失败', function () {
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);

    $sf = (new Kuaidi100Channel)->query('SF', 'SF12345678');
    expect($sf->success)->toBeFalse()->and($sf->message)->toContain('shunfeng')->and($sf->message)->toContain('手机号');

    $zto = (new Kuaidi100Channel)->query('ZTO', 'ZTO12345678', '');
    expect($zto->success)->toBeFalse();

    // 圆通等非必填公司不受影响
    $yto = (new Kuaidi100Channel)->query('YTO', 'YT12345678');
    expect($yto->success)->toBeTrue();

    // 顺丰/中通在发请求前就被拦截，实际只发出圆通这一次
    Http::assertSentCount(1);
});

test('TC-KD100-05 电商虚拟号取横线后四位', function () {
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);

    (new Kuaidi100Channel)->query('SF', 'SF12345678', '138****1234-5678');

    Http::assertSent(fn ($request) => json_decode((string) $request->data()['param'], true)['phone'] === '5678');
});

test('TC-KD100-06 轨迹文案映射为统一阶段', function () {
    Http::fake(['*' => Http::response(kd100Body('3', [
        kd100Trace('快件已揽收', '2026-09-01 09:00:00'),
        kd100Trace('快件已到达北京中转中心', '2026-09-01 12:00:00'),
        kd100Trace('正在派送途中', '2026-09-02 09:00:00'),
        kd100Trace('快件已签收，感谢使用', '2026-09-02 11:00:00'),
    ]), 200)]);

    $result = (new Kuaidi100Channel)->query('YTO', 'YT12345678');

    expect($result->success)->toBeTrue()
        ->and($result->traces)->toHaveCount(4)
        ->and(array_column($result->traces, 'stage'))->toBe([
            TraceStage::PICKUP,
            TraceStage::IN_TRANSIT,
            TraceStage::DELIVERING,
            TraceStage::DELIVERED,
        ])
        // 汇总状态：含签收 → delivered
        ->and($result->toTraceStatus())->toBe('delivered');
});

test('TC-KD100-07 文案未命中时以运单级 state 兜底', function () {
    Http::fake(['*' => Http::response(kd100Body('5', [
        kd100Trace('快件已到达【北京市】', '2026-09-01 09:00:00'),
        kd100Trace('快件正在运输途中', '2026-09-01 12:00:00'),
    ]), 200)]);

    $result = (new Kuaidi100Channel)->query('YTO', 'YT12345678');

    // 前两行文案均落入 in_transit；末行按 state=5（派件）兜底
    expect(array_column($result->traces, 'stage'))->toBe([
        TraceStage::IN_TRANSIT,
        TraceStage::DELIVERING,
    ]);
});

test('TC-KD100-08 业务失败与网络异常都收敛为 fail', function () {
    // status 非 200
    Http::fake(['*' => Http::response(['message' => '快递公司参数异常', 'status' => '400'], 200)]);
    $biz = (new Kuaidi100Channel)->query('YTO', 'YT12345678');
    expect($biz->success)->toBeFalse()->and($biz->message)->toBe('快递公司参数异常');

    // returnCode 分支
    Http::fake(['*' => Http::response(['returnCode' => '701', 'message' => 'key缺失', 'result' => false], 200)]);
    expect((new Kuaidi100Channel)->query('YTO', 'YT12345678')->success)->toBeFalse();

    // HTTP 5xx
    Http::fake(['*' => Http::response('error', 500)]);
    expect((new Kuaidi100Channel)->query('YTO', 'YT12345678')->success)->toBeFalse();

    // 连接异常
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
    $net = (new Kuaidi100Channel)->query('YTO', 'YT12345678');
    expect($net->success)->toBeFalse()->and($net->message)->toContain('请求异常');
});

test('TC-KD100-09 尚无轨迹属于正常态不计失败', function () {
    Http::fake(['*' => Http::response(kd100Body('0', []), 200)]);

    $result = (new Kuaidi100Channel)->query('YTO', 'YT12345678');

    expect($result->success)->toBeTrue()
        ->and($result->traces)->toBe([])
        // 空轨迹推导不出状态，TracePullService 据此保持原状
        ->and($result->toTraceStatus())->toBeNull();
});
