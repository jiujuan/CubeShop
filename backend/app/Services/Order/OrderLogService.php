<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 订单状态流水服务（V1.1 E02-A / T-001）
 *
 * 约定：order_logs 的唯一写入点。
 * 任何状态变更都应经 OrderService::transitionTo()，由其调用本服务，
 * 禁止各处接口自行写日志，避免出现重复或缺失的流水。
 */
class OrderLogService
{
    /**
     * 记录一条状态变更流水
     *
     * @param  string  $toStatus  变更后状态
     * @param  string  $operatorType  user / admin / system
     * @param  int|null  $operatorId  操作人 ID（system 可为空）
     * @param  string|null  $fromStatus  变更前状态（不传则取订单当前 status 字段的快照值）
     * @param  bool  $insideTransaction  是否已在事务内（由调用方保证原子性）
     */
    public function record(
        Order $order,
        string $toStatus,
        string $operatorType = OrderLog::OPERATOR_SYSTEM,
        ?int $operatorId = null,
        ?string $remark = null,
        ?string $fromStatus = null,
    ): ?OrderLog {
        try {
            return OrderLog::create([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'remark' => $remark !== null ? mb_substr($remark, 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // 流水写入失败不应阻断主流程，但必须留下痕迹
            report($e);

            return null;
        }
    }

    /**
     * 订单创建时的初始流水（from_status 为空）
     */
    public function recordCreated(Order $order, string $operatorType = OrderLog::OPERATOR_USER, ?int $operatorId = null): ?OrderLog
    {
        return $this->record($order, $order->status, $operatorType, $operatorId, '创建订单', null);
    }

    /**
     * 订单全部流水（按 id 升序，即时间顺序）
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(Order $order): array
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (OrderLog $log) => [
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'to_status_label' => $log->to_status_label,
                'operator_type' => $log->operator_type,
                'operator_id' => $log->operator_id,
                'remark' => $log->remark,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    /**
     * 批量写入（回填历史数据用，事务内）
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::table('order_logs')->insert($rows);
    }
}
