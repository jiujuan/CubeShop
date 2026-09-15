<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Report\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 经营报表（V1.1 F03 / T-020）
 * 权限：report.view
 *
 * 统计口径见 ReportService 类注释（全站唯一口径）。
 */
class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private ReportService $reports)
    {
    }

    /** GET /admin/reports/overview —— 核心指标卡 */
    public function overview(): JsonResponse
    {
        return $this->success($this->reports->overview());
    }

    /** GET /admin/reports/trend?days=30 —— 订单量与销售额趋势 */
    public function trend(Request $request): JsonResponse
    {
        $days = $this->validateDays($request);

        return $this->success($this->reports->trend($days));
    }

    /** GET /admin/reports/top-products?limit=10&days=30 —— 商品 TOP */
    public function topProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.ReportService::MAX_RANGE_DAYS],
        ]);

        return $this->success([
            'list' => $this->reports->topProducts((int) ($data['limit'] ?? 10), (int) ($data['days'] ?? 30)),
        ]);
    }

    /** GET /admin/reports/category-share?days=30 —— 分类销售额占比 */
    public function categoryShare(Request $request): JsonResponse
    {
        $days = $this->validateDays($request);

        return $this->success($this->reports->categoryShare($days));
    }

    /** GET /admin/reports/users?days=30 —— 用户增长与复购 */
    public function users(Request $request): JsonResponse
    {
        $days = $this->validateDays($request);

        return $this->success($this->reports->users($days));
    }

    /** GET /admin/reports/export?start=&end= —— 区间订单明细导出 */
    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        $start = Carbon::parse($data['start'])->startOfDay();
        $end = Carbon::parse($data['end'])->endOfDay();
        if ($start->diffInDays($end) > ReportService::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end' => ['导出区间不能超过 '.ReportService::MAX_RANGE_DAYS.' 天'],
            ]);
        }

        return $this->success($this->reports->exportOrders($data['start'], $data['end']));
    }

    private function validateDays(Request $request): int
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:'.ReportService::MAX_RANGE_DAYS],
        ]);

        return (int) ($data['days'] ?? 30);
    }
}
