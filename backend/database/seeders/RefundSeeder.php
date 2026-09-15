<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\Seeder;

/**
 * 退款处理演示数据（25 条，便于后台退款列表 / 审核 / 筛选测试）
 *
 * 规则：
 * - 不删除任何已有数据；refunds 表已有数据时跳过（幂等）
 * - 全部挂靠真实订单（FK order_id / 冗余 order_no / user_id 与订单一致）
 * - 待审核（pending）优先挂到 paid/shipped 订单，便于测试「同意/拒绝」的状态机流转
 * - 终态数据（success/rejected/failed/approved）分散到各订单，不渲染操作按钮
 */
class RefundSeeder extends Seeder
{
    public function run(): void
    {
        if (Refund::count() > 0) {
            $this->command->warn('refunds 表已有数据，跳过 RefundSeeder（不覆盖现有退款记录）');

            return;
        }

        /** @var \Illuminate\Support\Collection $orders */
        $orders = Order::query()
            ->orderBy('id')
            ->get(['id', 'order_no', 'user_id', 'pay_amount', 'status']);

        if ($orders->isEmpty()) {
            $this->command->warn('无订单数据，无法生成退款演示数据（请先跑 OrderSeeder）');

            return;
        }

        $processable = $orders->whereIn('status', ['paid', 'shipped', 'completed'])->values();
        $all = $orders->values();

        // 退款原因池（与真实电商场景对齐）
        $reasons = [
            '商品与描述不符，颜色色差明显',
            '尺码不合适，申请换货退款',
            '七天内无理由退货，商品未拆封',
            '物流运输破损，外包装变形',
            '发错货了，与我下单的型号不一致',
            '质量有问题，使用两天后出现异响',
            '重复下单，误拍了两件',
            '不想要了，申请仅退款',
            '商品有明显使用痕迹，疑似二手',
            '配件缺失，说明书与赠品没有',
            '效果不如预期，与直播间宣传不符',
            '保质期临近，要求退款补偿',
        ];

        // [订单下标, 状态, 金额比例, 原因下标, 后台备注]（共 25 条）
        // pending×8 / success×9 / rejected×5 / failed×2 / approved×1
        $plan = [
            // 待审核 8 条：优先 paid/shipped/completed 订单，便于直接点「同意/拒绝」测状态机
            ['processable', 'pending', 1.00, 0, null],
            ['processable', 'pending', 0.80, 1, null],
            ['processable', 'pending', 1.00, 3, null],
            ['processable', 'pending', 0.50, 6, null],
            ['processable', 'pending', 1.00, 2, null],
            ['all', 'pending', 0.30, 7, null],
            ['all', 'pending', 1.00, 10, null],
            ['all', 'pending', 0.60, 11, null],
            // 退款成功 9 条
            ['all', 'success', 1.00, 4, '沙箱退款成功'],
            ['all', 'success', 0.90, 8, '沙箱退款成功'],
            ['all', 'success', 1.00, 5, '沙箱退款成功'],
            ['all', 'success', 0.70, 9, '部分退款，扣除已使用部分'],
            ['all', 'success', 1.00, 2, '沙箱退款成功'],
            ['all', 'success', 1.00, 0, '沙箱退款成功'],
            ['all', 'success', 0.85, 1, '沙箱退款成功'],
            ['all', 'success', 1.00, 7, '沙箱退款成功'],
            ['all', 'success', 0.40, 10, '赠品部分不退，退主商品'],
            // 已拒绝 5 条
            ['all', 'rejected', 1.00, 7, '已拆封使用，不符合七天无理由条件'],
            ['all', 'rejected', 1.00, 10, '超过退款时限，建议联系商家换货'],
            ['all', 'rejected', 0.50, 1, '凭证不足，请补充商品照片后重新申请'],
            ['all', 'rejected', 1.00, 9, '商品无质量问题，配件可单独补发'],
            ['all', 'rejected', 1.00, 11, '保质期符合国家标准，不支持此理由退款'],
            // 失败 2 条（渠道退款失败）
            ['all', 'failed', 1.00, 5, '支付渠道退款接口超时，待重试'],
            ['all', 'failed', 0.80, 3, '原支付单已关闭，需线下打款'],
            // 审核通过待打款 1 条
            ['all', 'approved', 1.00, 0, '已同意，等待渠道打款'],
        ];

        $adminId = \App\Models\SysUser::query()->orderBy('id')->value('id') ?? 1;

        $seq = random_int(100001, 199999);
        $used = [];
        $nextNo = function () use (&$seq, &$used): string {
            do {
                $no = 'RF'.now()->format('Ymd').($seq++);
            } while (isset($used[$no]));
            $used[$no] = true;

            return $no;
        };

        // 待审核优先消费 paid/shipped 订单（每个可处理订单最多挂 2 条 pending，避免同单重复退款干扰测试）
        $processablePool = $processable->values();
        $processableCursor = 0;
        $allCursor = 0;
        $pendingPerOrder = [];

        $rows = [];
        foreach ($plan as [$pool, $status, $ratio, $reasonIdx, $remark]) {
            if ($pool === 'processable' && $processablePool->isNotEmpty()) {
                // 轮转取可处理订单，同一订单 pending 不超过 2 条
                for ($i = 0; $i < $processablePool->count(); $i++) {
                    $order = $processablePool[$processableCursor % $processablePool->count()];
                    $processableCursor++;
                    if (($pendingPerOrder[$order->id] ?? 0) < 2) {
                        break;
                    }
                }
                $pendingPerOrder[$order->id] = ($pendingPerOrder[$order->id] ?? 0) + 1;
            } else {
                $order = $all[$allCursor++ % $all->count()];
            }

            $terminal = in_array($status, ['success', 'rejected', 'failed', 'approved'], true);

            $rows[] = [
                'refund_no' => $nextNo(),
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'user_id' => $order->user_id,
                'amount' => bcmul((string) $order->pay_amount, (string) $ratio, 2),
                'reason' => $reasons[$reasonIdx],
                'status' => $status,
                'admin_remark' => $remark ?? ($terminal ? '演示数据' : null),
                'processed_by' => $terminal ? $adminId : null,
                'processed_at' => $terminal ? now()->subDays(random_int(0, 9))->subHours(random_int(1, 20)) : null,
                'created_at' => now()->subDays(random_int(0, 12))->subHours(random_int(1, 23)),
                'updated_at' => now(),
            ];
        }

        // 同订单的终态记录与 pending 记录可能撞单号序列，已在 nextNo 内去重；
        // refund_no 全局唯一约束由循环内 used 表保证
        foreach ($rows as $row) {
            Refund::create($row);
        }

        $count = Refund::count();
        $byStatus = Refund::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->command->info("RefundSeeder 完成：共 {$count} 条 ".json_encode($byStatus, JSON_UNESCAPED_UNICODE));
    }
}
