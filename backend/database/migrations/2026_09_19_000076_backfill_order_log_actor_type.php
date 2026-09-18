<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 回填订单模块操作日志的操作人归属（数据修正）
 *
 * 背景：`OrderService::createFromCart()` 与 `transitionTo()` 早期写 `sys_operation_log`
 * 时未显式传 `$actorType`，默认写成 `admin`，导致后台「操作日志」把买家下单 / 取消 /
 * 确认收货显示成管理员。
 *
 * 服务层已修正（create → customer；transitionTo 按 operatorType 映射）。
 * 本迁移回填历史误记数据——`order_logs` 是订单状态流的**唯一写入点**且操作人类型一直正确，
 * 故以其 `operator_type` 为准反查对应 `sys_operation_log` 行：
 *   order_logs.to_status = 'cancelled'  ↔ sys_operation_log.action = 'status_cancelled'
 *
 * 幂等：只处理仍为 admin / null 的行；`order_logs` 无对应记录时跳过（不臆测）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 买家下单流水（module=order / action=create 仅由 createFromCart 写入）
        DB::table('sys_operation_log')
            ->where('module', 'order')
            ->where('action', 'create')
            ->where(function ($q) {
                $q->where('actor_type', 'admin')->orWhereNull('actor_type');
            })
            ->update(['actor_type' => 'customer']);

        // 2. 退款中流水：refunding 仅由 RefundService::apply()（买家申请）触发，必然归属买家
        DB::table('sys_operation_log')
            ->where('module', 'order')
            ->where('action', 'status_'.Order::STATUS_REFUNDING)
            ->where(function ($q) {
                $q->where('actor_type', 'admin')->orWhereNull('actor_type');
            })
            ->update(['actor_type' => 'customer']);

        // 3. 其余状态流转流水：按 order_logs 的真实 operator_type 回填
        $rows = DB::table('sys_operation_log')
            ->where('module', 'order')
            ->where(function ($q) {
                $q->where('actor_type', 'admin')->orWhereNull('actor_type');
            })
            ->get(['id', 'target_id', 'action']);

        foreach ($rows as $row) {
            if (! str_starts_with((string) $row->action, 'status_') || ! $row->target_id) {
                continue;
            }

            $toStatus = substr($row->action, strlen('status_'));

            $operatorType = DB::table('order_logs')
                ->where('order_id', $row->target_id)
                ->where('to_status', $toStatus)
                ->orderBy('id')
                ->value('operator_type');

            if ($operatorType === 'user') {
                DB::table('sys_operation_log')
                    ->where('id', $row->id)
                    ->update(['actor_type' => 'customer']);
            }
        }
    }

    public function down(): void
    {
        // 数据修正类迁移，无有意义回滚
    }
};
