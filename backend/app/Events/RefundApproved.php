<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 退款审核通过（WMS 计划 P4 / Step 3）
 *
 * 仅 `return_refund` 类型触发：审核通过后退款单停在 approved + waiting_return，
 * 由监听器 {@see \App\Listeners\CreateReturnInboundOrder} 创建退货入库单并（按配置）
 * 推送 WMS。仅退款类型不发本事件（老路径零变化）。
 */
class RefundApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Refund $refund) {}
}
