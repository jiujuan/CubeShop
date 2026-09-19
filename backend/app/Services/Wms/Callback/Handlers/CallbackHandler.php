<?php

namespace App\Services\Wms\Callback\Handlers;

use App\Models\FulfillmentOrder;

/**
 * 回传消息处理器契约（WMS 计划 P3 / Step 4）
 *
 * 处理器只负责「归一消息 → 领域动作」，幂等占坑与告警由外层统一负责。
 * 处理失败抛任意异常 → 外层释放幂等占坑并重试/告警。
 */
interface CallbackHandler
{
    /**
     * @param  array<string, mixed>  $message  {@see CallbackMessageParser::parse()} 归一结构
     * @param  array{log_id: int|null, provider: string}  $ctx
     */
    public function handle(FulfillmentOrder $fo, array $message, array $ctx = []): void;

    /** 是否能处理该 msg_type（Dispatcher 路由用） */
    public function supports(string $msgType): bool;
}
