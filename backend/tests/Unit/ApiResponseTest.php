<?php

/**
 * ApiResponse trait 测试（TestPlan §3.1）：统一响应结构
 */
class ApiResponseFixture
{
    use App\Support\ApiResponse;

    public function callSuccess(): \Illuminate\Http\JsonResponse
    {
        return $this->success(['id' => 1]);
    }

    public function callFail(): \Illuminate\Http\JsonResponse
    {
        return $this->fail('业务失败', 40009);
    }
}

test('success 返回统一结构 code=0', function () {
    $resp = (new ApiResponseFixture)->callSuccess();

    expect($resp->status())->toBe(200)
        ->and($resp->getData(true))->toBe([
            'code' => 0,
            'message' => 'ok',
            'data' => ['id' => 1],
        ]);
});

test('fail 返回业务码与消息', function () {
    $resp = (new ApiResponseFixture)->callFail();

    expect($resp->getData(true)['code'])->toBe(40009)
        ->and($resp->getData(true)['message'])->toBe('业务失败')
        ->and($resp->getData(true)['data'])->toBeNull();
});
