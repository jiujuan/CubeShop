<?php

namespace App\Exceptions\Wms;

use RuntimeException;

/**
 * WMS 业务失败（WMS 计划 P2 / Step 3）
 *
 * 对方明确回执 `flag=failure`（或等价结构）时使用。**不可重试**——同样的报文
 * 再发一遍只会再失败一次，需要人工介入（补映射、改地址、联系仓方）。

 * 典型场景：取消一张「已出库」的单据（错过的取消窗口）。
 */
class WmsBizException extends RuntimeException
{
    /**
     * @param  string  $wmsCode  对方返回的业务码（如 S08）
     * @param  array<string, mixed>  $payload  对方回执的业务载荷（已脱敏前，仅内存传递）
     *
     * 注意属性名叫 `wmsCode` 而非 `code`：基类 `Exception::$code` 是**非 readonly** 的，
     * 子类不能把它重声明为 readonly。
     */
    public function __construct(
        string $message,
        public readonly ?string $wmsCode = null,
        public readonly array $payload = [],
    ) {
        parent::__construct($message, 0);
    }
}
