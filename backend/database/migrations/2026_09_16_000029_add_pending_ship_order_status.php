<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 新增「待发货」订单状态（pending_ship）
 *
 * 背景：原实现把「已支付」与「待发货」合并为唯一的 paid 状态，运营侧无法把
 * 「货款已到账」与「已进入发货队列」区分开。本次拆出独立状态，履约主链路变为：
 *
 *   待支付 → 已支付 → 待发货 → 已发货 → 已完成
 *
 * 存量数据处理：所有 `paid` 订单语义上都是「已付款、尚未发货」，因此整体搬迁为
 * `pending_ship`（直接落在发货队列里），同时补写一条 order_logs 流水，
 * 保证买家端订单时间轴的「等待发货」节点能正确点亮。
 *
 * 说明：orders.status 是 string(32) 字符串列而非数据库枚举，故只需同步列注释。
 */
return new class extends Migration
{
    private const LEGACY = 'pending_payment/paid/shipped/completed/cancelled/refunding/refunded';

    private const CURRENT = 'pending_payment/paid/pending_ship/shipped/completed/cancelled/refunding/refunded';

    public function up(): void
    {
        $this->migrate('paid', 'pending_ship', '系统迁移：拆分「待发货」状态');
        $this->comment(self::CURRENT);
    }

    public function down(): void
    {
        $this->migrate('pending_ship', 'paid', null);
        $this->comment(self::LEGACY);
    }

    /**
     * 批搬迁状态；$remark 非空时同步补写一条订单流水
     */
    private function migrate(string $from, string $to, ?string $remark): void
    {
        DB::table('orders')
            ->where('status', $from)
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($from, $to, $remark) {
                $ids = [];
                $rows = [];

                foreach ($orders as $order) {
                    $ids[] = $order->id;

                    if ($remark !== null) {
                        $rows[] = [
                            'order_id' => $order->id,
                            'from_status' => $from,
                            'to_status' => $to,
                            'operator_type' => 'system',
                            'operator_id' => null,
                            'remark' => $remark,
                            'created_at' => now(),
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('order_logs')->insert($rows);
                }

                DB::table('orders')->whereIn('id', $ids)->update(['status' => $to]);
            });
    }

    /** 同步 status 列注释（仅 MySQL / PostgreSQL 支持列注释） */
    private function comment(string $text): void
    {
        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement(
                "ALTER TABLE orders MODIFY COLUMN status VARCHAR(32) NOT NULL COMMENT '{$text}'"
            ),
            'pgsql' => DB::statement("COMMENT ON COLUMN orders.status IS '{$text}'"),
            default => null,
        };
    }
};
