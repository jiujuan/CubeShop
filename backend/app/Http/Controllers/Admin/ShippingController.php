<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipping;
use App\Services\Shipping\TracePullService;
use Illuminate\Http\Request;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 后台物流管理（V1.1 T-045 / T-047 前置接口）
 */
class ShippingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TracePullService $tracePull)
    {
    }

    /**
     * 物流看板列表（V1.1 T-047；权限 order.view）
     * GET /admin/shippings?trace_status=&keyword=&page=&page_size=
     *
     * 异常口径：trace_status=failed（连续拉取失败）；in_transit 且 shipped_at 超 48h 无轨迹 → stagnant 标记；
     * 列表按发货时间倒序，附订单号与买家便于跳转详情。
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trace_status' => ['nullable', 'string', 'in:'.implode(',', [Shipping::TRACE_PENDING, Shipping::TRACE_IN_TRANSIT, Shipping::TRACE_DELIVERED, Shipping::TRACE_FAILED])],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Shipping::query()
            ->with('order:id,order_no,user_id,status')
            ->withCount('traces')
            ->withMax('traces', 'occurred_at')
            ->when($data['trace_status'] ?? null, fn ($q, $s) => $q->where('trace_status', $s))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($w) => $w
                    ->where('tracking_no', 'like', "%{$kw}%")
                    ->orWhere('company_name', 'like', "%{$kw}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$kw}%")));
            })
            ->orderByDesc('shipped_at')
            ->orderByDesc('id');

        $page = $query->paginate($data['page_size'] ?? 20);
        $stagnantHours = 72;

        $items = collect($page->items())->map(function (Shipping $s) use ($stagnantHours) {
            $hoursSinceShip = $s->shipped_at ? $s->shipped_at->diffInHours(now()) : 0;
            // 轨迹停滞：in_transit 且最新轨迹距今超 72h（withMax 聚合列为字符串，需 parse）
            $stagnant = $s->trace_status === Shipping::TRACE_IN_TRANSIT
                && $s->traces_max_occurred_at !== null
                && \Carbon\Carbon::parse($s->traces_max_occurred_at)->diffInHours(now()) >= $stagnantHours;
            $stagnant = $stagnant || ($s->trace_status === Shipping::TRACE_IN_TRANSIT && $s->traces_count === 0 && $hoursSinceShip >= $stagnantHours);

            return [
                'id' => $s->id,
                'order_id' => $s->order_id,
                'order_no' => $s->order?->order_no,
                'company_code' => $s->company_code,
                'company_name' => $s->company_name,
                'tracking_no' => $s->tracking_no,
                'trace_status' => $s->trace_status,
                'trace_count' => $s->traces_count,
                'shipped_at' => $s->shipped_at?->toDateTimeString(),
                'delivered_at' => $s->delivered_at?->toDateTimeString(),
                'pull_fail_count' => $s->pull_fail_count,
                'last_fail_message' => $s->last_fail_message,
                // 异常标记：发货超 48h 无轨迹，或轨迹停滞超 72h（最近一条轨迹时间超限）
                'abnormal' => $s->trace_status === Shipping::TRACE_FAILED
                    || ($s->trace_status !== Shipping::TRACE_DELIVERED && $hoursSinceShip >= 48 && $s->traces_count === 0)
                    || $stagnant,
            ];
        })->all();

        return $this->success([
            'list' => $items,
            'pagination' => [
                'page' => $page->currentPage(),
                'page_size' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * 手动重试轨迹拉取（权限 order.ship）
     * POST /admin/shippings/{id}/pull
     */
    public function pull(int $id): JsonResponse
    {
        $shipping = Shipping::query()->find($id);
        if (! $shipping) {
            return $this->fail('物流记录不存在', 40004);
        }

        $result = $this->tracePull->pull($shipping);
        $shipping->refresh();

        $data = [
            'result' => $result,
            'trace_status' => $shipping->trace_status,
            'pull_fail_count' => $shipping->pull_fail_count,
            'trace_count' => $shipping->traces()->count(),
        ];

        if ($result === TracePullService::RESULT_FAILED) {
            return $this->fail('轨迹拉取失败（'.$shipping->trace_status.'）', 40000, $data);
        }

        return $this->success($data, $result === TracePullService::RESULT_SKIPPED ? '未配置查询渠道，已跳过' : '拉取成功');
    }
}
