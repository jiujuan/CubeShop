<?php

namespace App\Services\Report;

use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 经营报表聚合服务（V1.1 F03 / T-020）
 *
 * ── 统计口径（全站唯一，任何改动须同步 V1.0 仪表盘与文档）──
 * 1. 销售额：订单状态 ∈ {paid, pending_ship, shipped, completed, refunding}，按 `paid_at` 归属日期；
 *    - 已取消（cancelled）不计入；
 *    - 已退款（refunded）不计入（款已退回）；
 *    - 退款中（refunding）计入（款尚未退回，属在途收入）。
 * 2. 订单量：按 `created_at` 归属日期，含全部状态（口径 = 「下单量」）。
 * 3. 客单价 = 销售额 / 期间内已支付订单数。
 * 4. 支付转化率 = 期间内已支付订单数 / 期间内下单总数。
 * 5. 复购率 = 完成订单 ≥2 笔的用户数 / 有完成订单的用户数（全量口径，与区间无关）。
 * 6. 日期序列按天生成并补零，避免前端图表断点。
 */
class ReportService
{
    /**
     * 计入销售额的订单状态
     *
     * 注意：`pending_ship` 是支付成功后订单的**常驻**状态，缺了它销售额会凭空少一大截。
     */
    public const SALES_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_PENDING_SHIP,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
        Order::STATUS_REFUNDING,
    ];

    /** 允许的区间上限（天） */
    public const MAX_RANGE_DAYS = 90;

    /**
     * 核心指标卡（今日 / 昨日 / 近 7 日）
     */
    public function overview(): array
    {
        $today = $this->periodMetrics(now()->startOfDay(), now()->endOfDay());
        $yesterday = $this->periodMetrics(now()->subDay()->startOfDay(), now()->subDay()->endOfDay());
        $last7 = $this->periodMetrics(now()->subDays(6)->startOfDay(), now()->endOfDay());

        return [
            'today' => $today,
            'yesterday' => $yesterday,
            'last_7_days' => $last7,
            'pending' => [
                'ship' => Order::where('status', Order::STATUS_PENDING_SHIP)->count(),
                'refund' => Order::where('status', Order::STATUS_REFUNDING)->count(),
                'review' => Review::where('status', Review::STATUS_PENDING)->count(),
                'stock_warning' => $this->stockWarningCount(),
            ],
            'conversion_rate' => $today['conversion_rate'],
        ];
    }

    /**
     * 近 N 日订单量与销售额（默认 30，支持 7/30/90），缺失日期补 0
     */
    public function trend(int $days = 30): array
    {
        $days = $this->clampDays($days);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        // 订单量（按下单日期）
        $orderRows = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw($this->dateExpr('created_at').' as d, count(*) as cnt')
            ->groupBy('d')
            ->pluck('cnt', 'd')
            ->all();

        // 销售额（按支付日期，仅计入状态）
        $salesRows = Order::query()
            ->whereIn('status', self::SALES_STATUSES)
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw($this->dateExpr('paid_at').' as d, sum(pay_amount) as amt')
            ->groupBy('d')
            ->pluck('amt', 'd')
            ->all();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'orders' => (int) ($orderRows[$date] ?? 0),
                'sales' => number_format((float) ($salesRows[$date] ?? 0), 2, '.', ''),
            ];
        }

        return ['days' => $days, 'series' => $series];
    }

    /**
     * 商品 TOP N（按销量与销售额）
     */
    public function topProducts(int $limit = 10, int $days = 30): array
    {
        $limit = max(1, min($limit, 50));
        $days = $this->clampDays($days);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::SALES_STATUSES)
            ->whereBetween('orders.paid_at', [$start, $end])
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, sum(order_items.quantity) as qty, sum(order_items.total_amount) as amount')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();

        $titles = Product::withTrashed()
            ->whereIn('id', $rows->pluck('product_id'))
            ->pluck('title', 'id');

        return $rows->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'title' => $titles[$r->product_id] ?? '（已删除商品）',
            'quantity' => (int) $r->qty,
            'amount' => number_format((float) $r->amount, 2, '.', ''),
        ])->all();
    }

    /**
     * 分类销售额占比（合计 = 100%）
     */
    public function categoryShare(int $days = 30): array
    {
        $days = $this->clampDays($days);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('orders.status', self::SALES_STATUSES)
            ->whereBetween('orders.paid_at', [$start, $end])
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as category_id, categories.name as category_name, sum(order_items.total_amount) as amount')
            ->orderByDesc('amount')
            ->get();

        // 无归属商品归入「未分类」
        $total = (float) $rows->sum('amount');

        return [
            'total' => number_format($total, 2, '.', ''),
            'items' => $rows->map(fn ($r) => [
                'category_id' => $r->category_id ? (int) $r->category_id : null,
                'category_name' => $r->category_name ?: '未分类',
                'amount' => number_format((float) $r->amount, 2, '.', ''),
                'percent' => $total > 0 ? round((float) $r->amount / $total * 100, 1) : 0.0,
            ])->all(),
        ];
    }

    /**
     * 用户增长：近 N 日新增注册趋势 + 复购率
     */
    public function users(int $days = 30): array
    {
        $days = $this->clampDays($days);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        // 用户表拆分后买家独立成表（users），无需再按 customer 角色过滤
        $rows = User::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw($this->dateExpr('created_at').' as d, count(*) as cnt')
            ->groupBy('d')
            ->pluck('cnt', 'd')
            ->all();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $date, 'new_users' => (int) ($rows[$date] ?? 0)];
        }

        // 复购率：完成订单用户中下单 ≥2 笔的占比
        $counts = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as cnt')
            ->groupBy('user_id')
            ->get();

        $buyers = $counts->count();
        $repeatBuyers = $counts->where('cnt', '>=', 2)->count();

        return [
            'days' => $days,
            'series' => $series,
            'total_new_users' => array_sum(array_column($series, 'new_users')),
            'buyers' => $buyers,
            'repeat_buyers' => $repeatBuyers,
            'repurchase_rate' => $buyers > 0 ? round($repeatBuyers / $buyers * 100, 1) : 0.0,
        ];
    }

    /**
     * 区间订单明细导出（同步，行数上限保护）
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, truncated: bool, limit: int}
     */
    public function exportOrders(string $startDate, string $endDate, int $limit = 5000): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $query = Order::query()
            ->with('user:id,username,nickname')
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $orders = $query->limit($limit)->get();

        // 退款额汇总（仅成功退款）
        $refundMap = Refund::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('status', Refund::STATUS_SUCCESS)
            ->selectRaw('order_id, sum(amount) as amt')
            ->groupBy('order_id')
            ->pluck('amt', 'order_id')
            ->all();

        $rows = $orders->map(fn (Order $o) => [
            'order_no' => $o->order_no,
            'username' => $o->user?->nickname ?: $o->user?->username,
            'pay_amount' => (string) $o->pay_amount,
            'refund_amount' => number_format((float) ($refundMap[$o->id] ?? 0), 2, '.', ''),
            'status' => $o->status,
            'status_label' => Order::STATUS_LABELS[$o->status] ?? $o->status,
            'created_at' => $o->created_at?->format('Y-m-d H:i:s'),
            'paid_at' => $o->paid_at?->format('Y-m-d H:i:s'),
        ])->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'limit' => $limit,
        ];
    }

    // ---------- internals ----------

    /**
     * 某区间的核心指标
     *
     * @return array{orders:int, paid_orders:int, sales:string, aov:string, conversion_rate:float}
     */
    private function periodMetrics(Carbon $start, Carbon $end): array
    {
        $orders = Order::whereBetween('created_at', [$start, $end])->count();

        $paidQuery = Order::whereIn('status', self::SALES_STATUSES)
            ->whereBetween('paid_at', [$start, $end]);
        $paidOrders = (clone $paidQuery)->count();
        $sales = (float) $paidQuery->sum('pay_amount');

        return [
            'orders' => $orders,
            'paid_orders' => $paidOrders,
            'sales' => number_format($sales, 2, '.', ''),
            'aov' => number_format($paidOrders > 0 ? $sales / $paidOrders : 0, 2, '.', ''),
            'conversion_rate' => $orders > 0 ? round($paidOrders / $orders * 100, 1) : 0.0,
        ];
    }

    private function stockWarningCount(): int
    {
        $threshold = (int) app(\App\Services\Common\ConfigService::class)->getInt('inventory.warning_threshold', 10);

        return (int) DB::table('inventories')
            ->join('product_skus', 'product_skus.id', '=', 'inventories.sku_id')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->where('products.status', 1)
            ->where('product_skus.status', 1)
            ->where('inventories.stock', '<=', $threshold)
            ->count();
    }

    private function clampDays(int $days): int
    {
        return max(1, min($days, self::MAX_RANGE_DAYS));
    }

    /** 跨库日期截断表达式（SQLite / PostgreSQL / MySQL） */
    private function dateExpr(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default => "DATE({$column})",
        };
    }
}
