<?php

namespace App\Exceptions\Wms;

use RuntimeException;

/**
 * WMS 网关层异常（WMS 计划 P2 / Step 3）
 *
 * 仅表示「请求没能拿到可解读的业务回执」：DNS/连接失败、超时、非 2xx、
 * 响应体不是合法 JSON 等。**统一由 Adapter 捕获并转成 `WmsResult::fail(retryable: true)`**，
 * 不向业务层抛出——网络抖动属于可重试范畴，不是业务结论。
 *
 * 配置类错误（缺网关地址、缺 AppSecret）**不用本异常**，那类错误重试无意义，
 * 用 `BusinessException` 直接 fail-closed 暴露给操作者。
 */
class WmsGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $responseBody = null,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }
}
