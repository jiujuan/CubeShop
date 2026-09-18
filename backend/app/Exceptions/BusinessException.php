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

    public static function forbidden(string $message = '无权限执行此操作'): self
    {
        return new self(40003, $message);
    }

    /**
     * 限流 / 频控（SEC-07 账号锁定、SEC-09 导出频次）：HTTP 429
     *
     * 注意：中间件层抛出的 ThrottleRequestsException 在 bootstrap/app.php 中
     * 仍映射为 40009（历史约定，勿动），此处 40029 仅供业务代码主动限流使用。
     */
    public static function tooManyRequests(string $message = '操作过于频繁，请稍后再试'): self
    {
        return new self(40029, $message);
    }
}
