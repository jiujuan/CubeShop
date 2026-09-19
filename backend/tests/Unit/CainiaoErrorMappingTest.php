<?php

use App\Services\Wms\Adapters\Cainiao\CainiaoErrorCode;
use App\Services\Wms\Adapters\Cainiao\Signature;
use App\Services\Wms\Adapters\Cainiao\CainiaoGateway;

/**
 * 奇门错误归类（WMS 计划 P2 / F7、Step 4）
 *
 * 三类处置：可重试 / 不可重试 / 幂等成功。判据全部来自显式规则表，不做文本嗅探。
 */

test('HTTP 层可重试判定：无状态码 / 408 / 429 / 5xx 可重试，其余 4xx 不可', function () {
    expect(CainiaoErrorCode::isRetryableHttp(null))->toBeTrue()   // 网络异常/超时/DNS
        ->and(CainiaoErrorCode::isRetryableHttp(408))->toBeTrue()
        ->and(CainiaoErrorCode::isRetryableHttp(429))->toBeTrue()
        ->and(CainiaoErrorCode::isRetryableHttp(500))->toBeTrue()
        ->and(CainiaoErrorCode::isRetryableHttp(503))->toBeTrue()
        ->and(CainiaoErrorCode::isRetryableHttp(400))->toBeFalse()
        ->and(CainiaoErrorCode::isRetryableHttp(401))->toBeFalse()
        ->and(CainiaoErrorCode::isRetryableHttp(422))->toBeFalse();
});

test('幂等码判定：默认集合命中，大小写不敏感，支持自定义扩充', function () {
    expect(CainiaoErrorCode::isDuplicate('S07'))->toBeTrue()
        ->and(CainiaoErrorCode::isDuplicate('s07'))->toBeTrue()
        ->and(CainiaoErrorCode::isDuplicate('DELIVERY_ORDER_EXISTS'))->toBeTrue()
        ->and(CainiaoErrorCode::isDuplicate('S03'))->toBeFalse()
        ->and(CainiaoErrorCode::isDuplicate(null))->toBeFalse()
        // 自定义集合以传入者为准
        ->and(CainiaoErrorCode::isDuplicate('MY_DUP', ['MY_DUP']))->toBeTrue()
        ->and(CainiaoErrorCode::isDuplicate('S07', ['MY_DUP']))->toBeFalse();
});

test('retryable 综合判定：4xx（非 408/429）一票否决，其余按 HTTP + 业务码兜底', function () {
    // 凭证/参数错：即便业务码看着像系统错误，4xx 也判不可重试
    expect(CainiaoErrorCode::retryable(401, 'S01'))->toBeFalse()
        ->and(CainiaoErrorCode::retryable(400, null))->toBeFalse()
        // 5xx / 超时 → 可重试
        ->and(CainiaoErrorCode::retryable(500, null))->toBeTrue()
        ->and(CainiaoErrorCode::retryable(null, null))->toBeTrue()
        // 2xx 但业务码是系统类 → 仍可重试
        ->and(CainiaoErrorCode::retryable(200, 'SYSTEM_ERROR'))->toBeTrue()
        ->and(CainiaoErrorCode::retryable(200, 'S03'))->toBeFalse();
});

test('hint / describe：补中文提示但绝不覆盖对方原文', function () {
    expect(CainiaoErrorCode::hint('S07'))->toContain('幂等')
        ->and(CainiaoErrorCode::hint('S04'))->toContain('签名')
        ->and(CainiaoErrorCode::hint('UNKNOWN'))->toBeNull();

    $desc = CainiaoErrorCode::describe('S03', 'itemCode 不存在');
    expect($desc)->toContain('[S03]')
        ->and($desc)->toContain('itemCode 不存在')   // 原文保留
        ->and($desc)->toContain('参数');             // 附带中文提示
});

test('toResult：flag=success → 成功', function () {
    $gateway = new CainiaoGateway(new Signature);

    $result = $gateway->toResult([
        'http_status' => 200,
        'flag' => 'success',
        'code' => '0',
        'message' => 'success',
        'payload' => ['deliveryOrderId' => 'CN-1'],
        'request_id' => 'req-1',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->idempotent)->toBeFalse()
        ->and($result->data['deliveryOrderId'])->toBe('CN-1');
});

test('toResult：单据已存在 → 幂等成功（success + idempotent，绝不重复建单）', function () {
    $gateway = new CainiaoGateway(new Signature);

    $result = $gateway->toResult([
        'http_status' => 200,
        'flag' => 'failure',
        'code' => 'S07',
        'message' => '单据已存在',
        'payload' => ['deliveryOrderId' => 'CN-EXISTING'],
        'request_id' => 'req-2',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->idempotent)->toBeTrue()
        ->and($result->data['deliveryOrderId'])->toBe('CN-EXISTING');
});

test('toResult：业务失败但可重试性由 HTTP+码决定', function () {
    $gateway = new CainiaoGateway(new Signature);

    // 200 + S03（参数错）→ 不可重试
    $biz = $gateway->toResult([
        'http_status' => 200, 'flag' => 'failure', 'code' => 'S03', 'message' => '参数错', 'payload' => [],
    ]);
    expect($biz->success)->toBeFalse()
        ->and($biz->retryable)->toBeFalse()
        ->and($biz->error)->toContain('S03');

    // 200 + SYSTEM_ERROR → 可重试
    $sys = $gateway->toResult([
        'http_status' => 200, 'flag' => 'failure', 'code' => 'SYSTEM_ERROR', 'message' => '系统繁忙', 'payload' => [],
    ]);
    expect($sys->success)->toBeFalse()
        ->and($sys->retryable)->toBeTrue();
});

test('toResult 幂等码可被 duplicateCodes 参数覆盖（联调期按真实报文增补）', function () {
    $gateway = new CainiaoGateway(new Signature);

    $envelope = [
        'http_status' => 200, 'flag' => 'failure', 'code' => 'MY_DUP', 'message' => '重复', 'payload' => [],
    ];

    expect($gateway->toResult($envelope, ['MY_DUP'])->idempotent)->toBeTrue()
        ->and($gateway->toResult($envelope, ['OTHER'])->success)->toBeFalse();
});
