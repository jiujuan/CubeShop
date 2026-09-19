<?php

namespace App\Exceptions\Wms;

use RuntimeException;

/**
 * WMS 接口尚未实现（WMS 计划 P2 / Step 4）
 *
 * 用于「契约已定义但本期不实现」的方法，避免静默返回假成功。
 * 目前只有退货入库两式：菜鸟侧留到 **P4**（同网关、独立步骤）。
 *
 * 之所以不直接返回 `WmsResult::fail`：这是**代码缺陷**而非运行时故障，
 * 应该在测试/联调阶段就炸出来，而不是变成一条线上"随机失败"的日志。
 */
class WmsUnsupportedException extends RuntimeException
{
    public static function method(string $method): self
    {
        return new self("WMS 适配器方法 {$method} 尚未实现（菜鸟退货入库见计划 P4）");
    }

    /** 契约里已声明但本期未实现的方法名清单（供测试断言） */
    public static function pendingMethods(): array
    {
        return ['createReturnInbound', 'cancelReturnInbound'];
    }
}
