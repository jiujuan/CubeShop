<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

/**
 * WMS 定时任务注册（WMS 计划 P5 / F2～F5）
 *
 * ⚠️ 每一路都受开关控制：总开关 `wms.schedule.enabled`（env `WMS_SCHEDULE_ENABLED`）
 * 关掉后一个都不注册，单项开关可只停某一路。本地开发不想被定时任务打扰时
 * `WMS_SCHEDULE_ENABLED=false` 即可，不必改代码。
 *
 * 开关判定集中在 {@see planned()}（单一真源），`__invoke()` 只负责把计划
 * 应用到 Schedule——这样「某个开关下到底注册了什么」可被单测直接断言，
 * 不必依赖 Laravel 调度容器内部。
 */
class WmsScheduler
{
    /** @var list<array{command: string, method: string, args: array<int, string>, switch: string, desc: string}> */
    private const TASKS = [
        [
            'command' => 'wms:sync-inventory', 'method' => 'everyThirtyMinutes', 'args' => [],
            'switch' => 'sync_inventory', 'desc' => '每 30 分钟同步 WMS 库存快照（不改平台库存）',
        ],
        [
            'command' => 'wms:reconcile', 'method' => 'dailyAt', 'args' => ['02:30'],
            'switch' => 'reconcile', 'desc' => '每日 02:30 对账生成库存差异待办',
        ],
        [
            'command' => 'wms:prune-logs', 'method' => 'dailyAt', 'args' => ['04:00'],
            'switch' => 'prune_logs', 'desc' => '每日 04:00 清理 90 天前的接口报文日志',
        ],
        [
            'command' => 'wms:health --alert', 'method' => 'hourly', 'args' => [],
            'switch' => 'health_check', 'desc' => '每小时健康巡检（有问题才告警）',
        ],
    ];

    /**
     * 按当前配置把任务注册到调度器。
     */
    public function __invoke(Schedule $schedule): void
    {
        foreach ($this->planned() as $task) {
            $event = $schedule->command($task['command']);
            $event->{$task['method']}(...$task['args'])->withoutOverlapping();
        }
    }

    /**
     * 当前开关下**会**注册的任务（供测试与运维核对）。
     *
     * @return list<array{command: string, method: string, args: array<int, string>, switch: string, desc: string}>
     */
    public function planned(): array
    {
        if (! config('wms.schedule.enabled', true)) {
            return [];
        }

        return array_values(array_filter(
            self::TASKS,
            fn (array $task) => (bool) config('wms.schedule.'.$task['switch'], true),
        ));
    }
}
