<?php

namespace App\Services\Cs;

use App\Models\CsQuickReply;
use App\Models\CsTicket;
use Illuminate\Database\Eloquent\Collection;

/**
 * 快捷回复模板服务（CS-203）
 *
 * 单一职责：
 * 1. 按工单类型取用模板（通用 + 该类型专属，按 sort/id 排序）—— 供工作台下拉；
 * 2. 模板变量替换 render()—— 与前端插入口径一致，便于单元测。
 *
 * 写入（CRUD）由控制器直接走 Eloquent，逻辑简单无需下沉。
 */
class CsQuickReplyService
{
    /**
     * 某工单类型可用的模板 = 绑定该类型的 + 通用的（type_id 为 null）。
     *
     * 传 null 时只取通用模板（用于「无类型」工单或后台全量展示前的默认视图）。
     */
    public function forType(?int $typeId): Collection
    {
        return CsQuickReply::query()
            ->forType($typeId)
            ->ordered()
            ->get();
    }

    /**
     * 模板变量替换，与前端插入口径一致。
     *
     * 支持的占位符：
     * - {user_nickname} 工单发起人昵称（取不到时留空串，不抛错）
     * - {ticket_no}     工单号
     * - {order_no}      关联订单号（无订单时留空串）
     */
    public function render(string $template, CsTicket $ticket): string
    {
        $map = [
            '{user_nickname}' => $ticket->user?->nickname ?? '',
            '{ticket_no}' => $ticket->ticket_no ?? '',
            '{order_no}' => $ticket->order?->order_no ?? '',
        ];

        return str_replace(array_keys($map), array_values($map), $template);
    }
}
