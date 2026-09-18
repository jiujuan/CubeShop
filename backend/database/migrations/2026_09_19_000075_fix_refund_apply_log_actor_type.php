<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修正退款申请流水的操作人归属（数据回填）
 *
 * 背景：`RefundService::apply()` 早期调用 `OperationLogService::record()` 时未显式传
 * `$actorType`，日志默认写成 `admin`（服务默认值），导致后台「处理记录」把
 * 「用户提交申请」的申请人显示成管理员。
 *
 * 服务层已修正为显式传 `customer`；本迁移回填历史误记数据。
 * 幂等：只更新仍为 admin / null 的退款申请流水。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sys_operation_log')
            ->where('module', 'refund')
            ->where('action', 'apply')
            ->where('target_type', 'refund')
            ->where(function ($q) {
                $q->where('actor_type', 'admin')->orWhereNull('actor_type');
            })
            ->update(['actor_type' => 'customer']);
    }

    public function down(): void
    {
        // 数据修正类迁移，无有意义回滚（无法还原原始误记状态）
    }
};
