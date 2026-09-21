<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 清理重复轨迹（V1.1 三期）
 *
 * 背景：MockChannel 曾用 now() 生成轨迹时间，而 TracePullService 的去重键是
 * `occurred_at|context`，时间戳每次都变 → 去重永不命中 → 每 30 分钟插入一批
 * 内容相同的重复轨迹（用户端订单页物流信息「满屏」）。MockChannel 已改为确定性时间，
 * 本迁移负责清理**已产生的历史脏数据**。
 *
 * 规则（保守）：按 (shipping_id, context, occurred_at 日期) 分组保留 id 最小的一条。
 * 真实轨迹中「同一天、同一描述」重复基本都是脏数据；跨天同名（如两次中转）不受影响。
 *
 * 幂等：无重复时不做任何删除，可安全重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        $pruned = 0;

        DB::table('shipping_traces')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$pruned) {
                /** @var array<int, int> $keep 分组键 → 保留的 id */
                $keep = [];
                $drop = [];

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $shippingId = (int) $row['shipping_id'];
                    $context = (string) $row['context'];
                    // 只取日期部分，避免时区/精度差异导致误判
                    $day = substr((string) $row['occurred_at'], 0, 10);
                    $key = $shippingId.'|'.$day.'|'.$context;

                    if (! array_key_exists($key, $keep)) {
                        $keep[$key] = (int) $row['id'];

                        continue;
                    }

                    $drop[] = (int) $row['id'];
                }

                if ($drop !== []) {
                    DB::table('shipping_traces')->whereIn('id', $drop)->delete();
                    $pruned += count($drop);
                }
            }, 'id');

        if ($pruned > 0) {
            logger()->info('已清理重复物流轨迹', ['count' => $pruned]);
        }
    }

    /**
     * 删除的数据无法恢复，故 down() 不回滚。
     *
     * 重复轨迹本身无业务价值，回滚后反而会重新引入展示噪声。
     */
    public function down(): void
    {
        // intentionally empty
    }
};
