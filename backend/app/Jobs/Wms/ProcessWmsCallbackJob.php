<?php

namespace App\Jobs\Wms;

use App\Models\SysOperationLog;
use App\Services\Common\OperationLogService;
use App\Services\Notification\NotificationService;
use App\Services\Wms\Callback\CallbackDeduplicator;
use App\Services\Wms\Callback\CallbackDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * 回传消息异步处理（WMS 计划 P3 / Step 4）
 *
 * 同步层（{@see \App\Services\Wms\Callback\WmsCallbackService}）只做安全校验与落痕，
 * 业务处理进本 Job：幂等占坑 → 分发 → 失败释放占坑重试。
 *
 * 重试语义：tries=3，指数退避；最终失败由 failed() 告警（审计 + 站内信）。
 */
class ProcessWmsCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $provider,
        /** @var array<string, mixed> {@see \App\Services\Wms\Callback\CallbackMessageParser::parse()} 归一结构 */
        public readonly array $message,
        public readonly ?int $logId = null,
    ) {}

    public function handle(CallbackDispatcher $dispatcher, CallbackDeduplicator $deduplicator): void
    {
        $bizNo = (string) ($this->message['biz_no'] ?? '');
        $msgType = (string) ($this->message['msg_type'] ?? '');
        $statusKey = (string) ($this->message['status_key'] ?? '');

        if ($bizNo === '' || $msgType === '') {
            // 不可路由——Dispatcher 会告警，这里不占坑
            $dispatcher->dispatch($this->provider, $this->message, ['log_id' => $this->logId]);

            return;
        }

        // 业务幂等占坑：已处理过 → 直接返回（对方重推被吞）
        if (! $deduplicator->claimEvent($this->provider, $bizNo, $msgType, $statusKey)) {
            return;
        }

        try {
            $result = $dispatcher->dispatch($this->provider, $this->message, ['log_id' => $this->logId]);

            // 单据不存在：可能是先推后建，释放占坑以便单据补建后人工重推可再次处理
            if (($result['status'] ?? '') === 'unknown_order') {
                $deduplicator->releaseEvent($this->provider, $bizNo, $msgType, $statusKey);
            }
        } catch (Throwable $e) {
            // 释放占坑：本轮重试或对方重推仍可再次处理
            $deduplicator->releaseEvent($this->provider, $bizNo, $msgType, $statusKey);

            throw $e;
        }
    }

    /** 最终失败（重试耗尽）：审计 + 站内告警转人工 */
    public function failed(Throwable $e, OperationLogService $operationLog, NotificationService $notifications): void
    {
        $detail = sprintf(
            '回传处理重试耗尽（provider=%s, biz_no=%s, msg_type=%s, status_key=%s）：%s',
            $this->provider,
            (string) ($this->message['biz_no'] ?? ''),
            (string) ($this->message['msg_type'] ?? ''),
            (string) ($this->message['status_key'] ?? ''),
            $e->getMessage(),
        );

        try {
            $operationLog->record(
                null,
                'wms',
                'callback_job_failed',
                'wms_callback',
                (int) ($this->logId ?? 0),
                ['provider' => $this->provider, 'detail' => $detail],
                SysOperationLog::ACTOR_ADMIN,
            );
        } catch (Throwable) {
        }

        try {
            $notifications->sendToPermission(
                'wms.order.view',
                NotificationService::TYPE_WMS_ALERT,
                '[WMS] 回传处理失败（重试耗尽）',
                $detail,
            );
        } catch (Throwable) {
        }
    }
}
