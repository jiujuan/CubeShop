<?php

namespace App\Services\Wms;

use App\Models\FulfillmentOrder;
use App\Models\ReturnInboundOrder;
use App\Models\SysOperationLog;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Services\Common\OperationLogService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * WMS 健康巡检（WMS 计划 P5 / F5）
 *
 * 把「散落在各处的异常单据」汇总成一份可读报告：配置缺失、推送卡死、
 * 推送失败、异常单、接口失败率、队列积压。
 *
 * 设计取向：
 * - **只读**：绝不修改任何业务数据，巡检出问题只能由人或既有入口处置；
 * - **不抛**：任何一项统计失败都降级为该项 `unknown`，其余照常输出——
 *   巡检本身挂掉比发现问题更糟；
 * - 结果双通道落地：审计日志（`sys_operation_log`，可检索）+ 站内信
 *   （推 `wms.order.manage` 持有者，只有存在 warning/error 时才发，避免噪音）。
 */
class WmsHealthCheckService
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        private readonly OperationLogService $operationLog,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * 采集健康指标。
     *
     * @return array{checked_at: string, healthy: bool, summary: array<string, mixed>, checks: list<array<string, mixed>>}
     */
    public function collect(): array
    {
        $checks = [
            $this->checkConfig(),
            $this->checkStuckPushing(),
            $this->checkPushFailed(),
            $this->checkException(),
            $this->checkApiFailRate(),
            $this->checkQueue(),
        ];

        $healthy = ! collect($checks)->contains(
            fn (array $c) => in_array($c['status'], [self::STATUS_WARNING, self::STATUS_ERROR], true),
        );

        $summary = [
            'pushing_timeout' => (int) ($this->value($checks, 'pushing_timeout') ?? 0),
            'push_failed' => (int) ($this->value($checks, 'push_failed') ?? 0),
            'exception' => (int) ($this->value($checks, 'exception') ?? 0),
            'fail_rate' => (float) ($this->value($checks, 'fail_rate') ?? 0),
            'queue_backlog' => (int) ($this->value($checks, 'queue_backlog') ?? 0),
        ];

        return [
            'checked_at' => now()->toDateTimeString(),
            'healthy' => $healthy,
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    /**
     * 采集并（在有问题时）告警：审计 + 站内信。
     *
     * @return array 同 {@see collect()}
     */
    public function collectAndAlert(): array
    {
        $report = $this->collect();

        if ($report['healthy']) {
            return $report;
        }

        $problems = collect($report['checks'])
            ->filter(fn (array $c) => in_array($c['status'], [self::STATUS_WARNING, self::STATUS_ERROR], true))
            ->map(fn (array $c) => sprintf('[%s] %s：%s', $c['status'], $c['title'], $c['detail']))
            ->implode("\n");

        try {
            $this->operationLog->record(
                null,
                'wms',
                'health_alert',
                'wms',
                null,
                ['checks' => $report['checks'], 'summary' => $report['summary']],
                SysOperationLog::ACTOR_ADMIN,
            );
        } catch (Throwable) {
            // 审计失败不阻断
        }

        try {
            $this->notifications->sendToPermission(
                'wms.order.manage',
                NotificationService::TYPE_WMS_ALERT,
                '[WMS] 健康巡检发现问题',
                $problems,
            );
        } catch (Throwable) {
            // 告警失败不阻断
        }

        return $report;
    }

    // ---------------- 各项检查 ----------------

    /** 配置：未启用 / 启用了但凭证缺失 */
    private function checkConfig(): array
    {
        $configs = WmsConfig::query()->get();
        $enabled = $configs->where('enabled', true);

        if ($enabled->isEmpty()) {
            return $this->check('config', self::STATUS_WARNING, 'WMS 对接配置',
                $configs->isEmpty() ? '尚未配置任何仓库的 WMS 对接' : '所有仓库的 WMS 对接均已停用',
                ['total' => $configs->count(), 'enabled' => 0]);
        }

        $missing = $enabled->filter(fn (WmsConfig $c) => empty($c->app_key) || empty($c->app_secret))->values();

        if ($missing->isNotEmpty()) {
            return $this->check('config', self::STATUS_ERROR, 'WMS 对接配置',
                sprintf('%d 个已启用仓库缺少凭证（AppKey/AppSecret）', $missing->count()),
                ['enabled' => $enabled->count(), 'missing_credentials' => $missing->pluck('warehouse_id')->all()]);
        }

        return $this->check('config', self::STATUS_OK, 'WMS 对接配置',
            sprintf('%d 个仓库已启用且凭证齐备', $enabled->count()),
            ['enabled' => $enabled->count()]);
    }

    /** 卡在 pushing 超过阈值（正常推送应在秒级完成） */
    private function checkStuckPushing(): array
    {
        $minutes = max(1, (int) config('wms.health.pushing_timeout_minutes', 30));
        $before = now()->subMinutes($minutes);

        try {
            $outbound = FulfillmentOrder::query()
                ->where('status', FulfillmentOrder::STATUS_PUSHING)
                ->where('updated_at', '<', $before)
                ->count();
            $returns = ReturnInboundOrder::query()
                ->where('status', ReturnInboundOrder::STATUS_PUSHING)
                ->where('updated_at', '<', $before)
                ->count();
        } catch (Throwable $e) {
            return $this->check('pushing_timeout', self::STATUS_UNKNOWN, '推送卡死巡检',
                '统计失败：'.$e->getMessage(), ['minutes' => $minutes]);
        }

        $total = $outbound + $returns;
        $status = $total > 0 ? self::STATUS_WARNING : self::STATUS_OK;

        return $this->check('pushing_timeout', $status, '推送卡死巡检',
            $total > 0
                ? sprintf('%d 张单据卡在「推送中」超过 %d 分钟（发货 %d / 退货 %d）', $total, $minutes, $outbound, $returns)
                : sprintf('无单据卡在「推送中」超过 %d 分钟', $minutes),
            ['total' => $total, 'outbound' => $outbound, 'return_inbound' => $returns, 'minutes' => $minutes]);
    }

    /** 推送失败待处理 */
    private function checkPushFailed(): array
    {
        try {
            $outbound = FulfillmentOrder::query()->where('status', FulfillmentOrder::STATUS_PUSH_FAILED)->count();
            $returns = ReturnInboundOrder::query()->where('status', ReturnInboundOrder::STATUS_PUSH_FAILED)->count();
        } catch (Throwable $e) {
            return $this->check('push_failed', self::STATUS_UNKNOWN, '推送失败单据', '统计失败：'.$e->getMessage());
        }

        $total = $outbound + $returns;

        return $this->check('push_failed', $total > 0 ? self::STATUS_WARNING : self::STATUS_OK, '推送失败单据',
            $total > 0
                ? sprintf('%d 张单据推送失败待处理（发货 %d / 退货 %d）', $total, $outbound, $returns)
                : '无推送失败单据',
            ['total' => $total, 'outbound' => $outbound, 'return_inbound' => $returns]);
    }

    /** 异常单据（缺映射等，需人工修复后重推） */
    private function checkException(): array
    {
        try {
            $outbound = FulfillmentOrder::query()->where('status', FulfillmentOrder::STATUS_EXCEPTION)->count();
            $returns = ReturnInboundOrder::query()->where('status', ReturnInboundOrder::STATUS_EXCEPTION)->count();
        } catch (Throwable $e) {
            return $this->check('exception', self::STATUS_UNKNOWN, '异常单据', '统计失败：'.$e->getMessage());
        }

        $total = $outbound + $returns;

        return $this->check('exception', $total > 0 ? self::STATUS_WARNING : self::STATUS_OK, '异常单据',
            $total > 0
                ? sprintf('%d 张单据处于异常态（发货 %d / 退货 %d）', $total, $outbound, $returns)
                : '无异常单据',
            ['total' => $total, 'outbound' => $outbound, 'return_inbound' => $returns]);
    }

    /** 近 24 小时接口失败率（含回调入站与主动出站） */
    private function checkApiFailRate(): array
    {
        $hours = max(1, (int) config('wms.health.window_hours', 24));
        $threshold = (float) config('wms.health.fail_rate', 0.2);

        try {
            $total = WmsApiLog::query()->where('created_at', '>=', now()->subHours($hours))->count();
            $failed = WmsApiLog::query()
                ->where('created_at', '>=', now()->subHours($hours))
                ->where('success', false)
                ->count();
        } catch (Throwable $e) {
            return $this->check('fail_rate', self::STATUS_UNKNOWN, '接口失败率', '统计失败：'.$e->getMessage());
        }

        if ($total === 0) {
            return $this->check('fail_rate', self::STATUS_OK, '接口失败率',
                sprintf('近 %d 小时无 WMS 接口调用', $hours),
                ['total' => 0, 'failed' => 0, 'rate' => 0.0, 'threshold' => $threshold]);
        }

        $rate = round($failed / $total, 4);

        return $this->check('fail_rate', $rate > $threshold ? self::STATUS_WARNING : self::STATUS_OK, '接口失败率',
            sprintf('近 %d 小时 %d 次调用中失败 %d 次（%.1f%%），阈值 %.0f%%',
                $hours, $total, $failed, $rate * 100, $threshold * 100),
            ['total' => $total, 'failed' => $failed, 'rate' => $rate, 'threshold' => $threshold]);
    }

    /** 队列积压与失败作业 */
    private function checkQueue(): array
    {
        $threshold = max(1, (int) config('wms.health.queue_backlog', 500));

        try {
            $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        } catch (Throwable $e) {
            return $this->check('queue_backlog', self::STATUS_UNKNOWN, '队列积压', '统计失败：'.$e->getMessage());
        }

        $status = self::STATUS_OK;
        if ($pending > $threshold) {
            $status = self::STATUS_WARNING;
        }
        if ($failed > 0) {
            $status = self::STATUS_WARNING;
        }

        return $this->check('queue_backlog', $status, '队列积压',
            sprintf('待处理 %d 条（阈值 %d），失败作业 %d 条', $pending, $threshold, $failed),
            ['pending' => $pending, 'failed' => $failed, 'threshold' => $threshold]);
    }

    // ---------------- 内部 ----------------

    /** @return array<string, mixed> */
    private function check(string $key, string $status, string $title, string $detail, array $value = []): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            'value' => $value,
        ];
    }

    private function value(array $checks, string $key): mixed
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) {
                return $check['value']['total'] ?? $check['value']['rate'] ?? $check['value']['pending'] ?? null;
            }
        }

        return null;
    }
}
