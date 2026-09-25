<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentReconcileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台对账看板（A7 增强：web 商城侧只读汇总）
 *
 * 面向持有 payment.reconcile.view 权限的运营/财务人员：登录商城后可在此查看
 * 对账批次/差异的整体态势（统计卡、类型分布、按渠道拆分、近 14 天趋势）。
 *
 * 安全边界（与 admin 侧区分）：
 * - 只读汇总，不含差异工单明细（交易单号、长短款金额逐笔数据留在 admin）；
 * - 无处置能力（resolve/ignore 仅 admin）；
 * - 路由由 permission:payment.reconcile.view 保护，买家账号（无 spatie）天然 403。
 */
class PaymentReconcileDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentReconcileService $reconcile,
    ) {}

    /**
     * 对账看板汇总
     * GET /payment-reconcile/dashboard?platform=web|h5|miniprogram（不传 = 全部平台）
     */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:web,h5,miniprogram'],
        ]);

        return $this->success($this->reconcile->stats($data['platform'] ?? null));
    }
}
