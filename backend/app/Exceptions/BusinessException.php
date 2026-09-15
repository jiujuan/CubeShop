<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 业务异常：message 给用户看，businessCode 对齐 API 文档错误码段
 * （40000 参数 / 40001 未登录 / 40003 无权限 / 40004 不存在 / 40009 业务冲突 / 50000 系统错误）
 */
class BusinessException extends RuntimeException
{
    public function __construct(
        public readonly int $businessCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function badRequest(string $message = '参数错误'): self
    {
        return new self(40000, $message);
    }

    public static function conflict(string $message = '业务规则冲突'): self
    {
        return new self(40009, $message);
    }

    public static function notFound(string $message = '资源不存在'): self
    {
        return new self(40004, $message);
    }
}
