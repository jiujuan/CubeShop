<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Order;
use App\Services\Common\ConfigService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 数据概览（API 文档 8.5 / Roadmap P6）
 */
class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ConfigService $config,
    ) {}

    /**
     * GET /admin/dashboard（权限 dashboard.view）
     * 今日/昨日订单量与销售额、待发货、待退款、库存预警
     */
    public function index(Request $request): JsonResponse
    {
        $salesStatuses = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, Order::STATUS_REFUNDING];

        // 今日 / 昨日订单量
        $todayOrders = Order::whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])->count();
        $yesterdayOrders = Order::whereBetween('created_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()])->count();

        // 销售额：按支付时间统计（取消/未支付不计入）
        $todaySales = Order::whereIn('status', $salesStatuses)
            ->whereBetween('paid_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('pay_amount');
        $yesterdaySales = Order::whereIn('status', $salesStatuses)
            ->whereBetween('paid_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()])
            ->sum('pay_amount');

        $pendingShip = Order::where('status', Order::STATUS_PAID)->count();
        $pendingRefund = Order::where('status', Order::STATUS_REFUNDING)->count();

        return $this->success([
            'today_orders' => $todayOrders,
            'today_sales' => number_format((float) $todaySales, 2, '.', ''),
            'yesterday_orders' => $yesterdayOrders,
            'yesterday_sales' => number_format((float) $yesterdaySales, 2, '.', ''),
            'pending_ship' => $pendingShip,
            'pending_refund' => $pendingRefund,
            'stock_warnings' => $this->stockWarnings(),
            'stock_warning_threshold' => $this->config->getInt('inventory.warning_threshold', 10),
        ]);
    }

    /**
     * 库存预警：在售商品中可用库存 ≤ 阈值的 SKU（Roadmap P6）
     */
    private function stockWarnings(): array
    {
        $threshold = $this->config->getInt('inventory.warning_threshold', 10);

        return Inventory::query()
            ->join('product_skus', 'product_skus.id', '=', 'inventories.sku_id')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->where('products.status', 1)
            ->where('product_skus.status', 1)
            ->where('inventories.stock', '<=', $threshold)
            ->orderBy('inventories.stock')
            ->limit(20)
            ->get([
                'inventories.sku_id',
                'products.title as product_title',
                'product_skus.specs',
                'inventories.stock',
                'inventories.locked_stock',
            ])
            ->map(fn ($row) => [
                'sku_id' => $row->sku_id,
                'product_title' => $row->product_title,
                'specs' => $row->specs ?? [],
                'stock' => $row->stock,
                'locked_stock' => $row->locked_stock,
            ])
            ->all();
    }
}
