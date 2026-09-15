<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 历史订单状态流水回填（V1.1 T-001 步骤 5）
 *
 * 依据 orders 上的时间戳字段（paid_at/shipped_at/completed_at/cancelled_at）
 * 反推生成初始 order_logs，remark 统一标注「历史回填」。
 *
 * 幂等：已有流水的订单直接跳过，重复执行不会产生重复数据。
 */
class BackfillOrderLogs extends Command
{
    protected $signature = 'orders:backfill-logs {--chunk=500 : 分批大小} {--dry-run : 仅统计不写入}';

    protected $description = '依据订单时间戳回填历史状态流水（幂等，可重复执行）';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        // 已有流水的订单 ID（一次性载入，回填场景数据量可控）
        $hasLogIds = DB::table('order_logs')->distinct()->pluck('order_id')->all();
        $hasLog = array_flip($hasLogIds);

        $total = 0;
        $skipped = 0;
        $rows = [];

        Order::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use (&$rows, &$total, &$skipped, $hasLog) {
                foreach ($orders as $order) {
                    if (isset($hasLog[$order->id])) {
                        $skipped++;

                        continue;
                    }

                    foreach ($this->buildRows($order) as $row) {
                        $rows[] = $row;
                        $total++;
                    }
                }

                // 分批落库，避免一次性占用过多内存
                if (! $this->option('dry-run') && count($rows) >= 1000) {
                    DB::table('order_logs')->insert($rows);
                    $rows = [];
                }
            });

        if ($dryRun) {
            $this->info("将回填 {$total} 条流水（跳过已有流水的订单 {$skipped} 笔）");

            return self::SUCCESS;
        }

        if ($rows !== []) {
            DB::table('order_logs')->insert($rows);
        }

        $this->info("回填完成：写入 {$total} 条流水，跳过 {$skipped} 笔已有流水的订单");

        return self::SUCCESS;
    }

    /**
     * 依据时间戳生成该订单的流水行（按时间升序）
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(Order $order): array
    {
        $nodes = [
            ['at' => $order->created_at, 'to' => Order::STATUS_PENDING_PAYMENT, 'remark' => '历史回填：创建订单'],
            ['at' => $order->paid_at, 'to' => Order::STATUS_PAID, 'remark' => '历史回填：支付成功'],
            ['at' => $order->shipped_at, 'to' => Order::STATUS_SHIPPED, 'remark' => '历史回填：商家发货'],
            ['at' => $order->completed_at, 'to' => Order::STATUS_COMPLETED, 'remark' => '历史回填：订单完成'],
            ['at' => $order->cancelled_at, 'to' => Order::STATUS_CANCELLED, 'remark' => $order->cancel_reason ?: '历史回填：订单取消'],
        ];

        // 退款中/已退款：无专用时间戳，以 updated_at 近似
        if (in_array($order->status, [Order::STATUS_REFUNDING, Order::STATUS_REFUNDED], true)) {
            $nodes[] = ['at' => $order->updated_at, 'to' => $order->status, 'remark' => '历史回填：退款流程状态'];
        }

        $nodes = array_values(array_filter($nodes, fn ($n) => $n['at'] !== null));
        usort($nodes, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);

        $rows = [];
        $prev = null;
        foreach ($nodes as $node) {
            $rows[] = [
                'order_id' => $order->id,
                'from_status' => $prev,
                'to_status' => $node['to'],
                'operator_type' => $prev === null ? 'user' : 'system',
                'operator_id' => $prev === null ? $order->user_id : null,
                'remark' => mb_substr((string) $node['remark'], 0, 255),
                'created_at' => $node['at']->format('Y-m-d H:i:s'),
            ];
            $prev = $node['to'];
        }

        return $rows;
    }
}
