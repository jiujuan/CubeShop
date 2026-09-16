<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\UserCoupon;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 优惠券管理（V1.1 二期 F06 / T-032）
 * 权限：marketing.manage
 *
 * 规则要点：**已发放的券仅允许改名称/停止发放/延长有效期**，不得改面额与门槛
 * （防止已领券的优惠金额漂移）。见 update()。
 */
class CouponController extends Controller
{
    use ApiResponse;

    /** 券类型/范围/有效期/状态 的合法取值 */
    private const TYPES = [Coupon::TYPE_FIXED, Coupon::TYPE_PERCENT];

    private const SCOPES = [Coupon::SCOPE_ALL, Coupon::SCOPE_CATEGORY, Coupon::SCOPE_PRODUCT];

    private const VALID_TYPES = [Coupon::VALID_ABSOLUTE, Coupon::VALID_RELATIVE];

    private const STATUSES = [Coupon::STATUS_ACTIVE, Coupon::STATUS_STOPPED];

    /** 已发放后禁止修改的核心字段（面额/门槛等，改了会导致已领券价值漂移） */
    private const LOCKED_AFTER_ISSUED = [
        'type', 'amount', 'percent', 'min_spend', 'max_discount',
        'scope', 'scope_refs', 'total_count', 'per_user_limit',
        'valid_type', 'valid_from', 'valid_days',
    ];

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 券列表（管理端） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'type' => ['nullable', 'in:'.implode(',', self::TYPES)],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Coupon::query()
            ->when($data['keyword'] ?? null, fn ($q, $kw) => $q->where('name', 'like', '%'.$kw.'%'))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['start_time'] ?? null, fn ($q, $t) => $q->where('created_at', '>=', $t))
            ->when($data['end_time'] ?? null, fn ($q, $t) => $q->where('created_at', '<=', $t))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Coupon $c) => $this->format($c));

        return $this->paginated($paginator);
    }

    /** 创建券 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->createRules());

        if (Coupon::where('name', $data['name'])->exists()) {
            throw BusinessException::conflict('券名称已存在');
        }
        $this->assertScopeRefsValid($data['scope'], $data['scope_refs'] ?? []);
        if ($data['valid_type'] === Coupon::VALID_ABSOLUTE) {
            $this->assertAbsoluteWindow($data);
        }

        $coupon = Coupon::create($this->normalize($data) + ['issued_count' => 0, 'used_count' => 0]);

        $this->opLog->record($request->user()?->id, 'coupon', 'create', 'Coupon', $coupon->id, [
            'name' => $coupon->name, 'type' => $coupon->type,
        ]);

        return $this->success(['id' => $coupon->id], '创建成功');
    }

    /** 更新券（已发放：仅名称/停发/延长有效期） */
    public function update(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::find($id);
        if (! $coupon) {
            throw BusinessException::notFound('优惠券不存在');
        }

        $data = $request->validate($this->updateRules($id));

        // 已发放的券：禁止触碰核心字段
        if ($coupon->issued_count > 0) {
            foreach (self::LOCKED_AFTER_ISSUED as $field) {
                if (array_key_exists($field, $data)) {
                    throw BusinessException::conflict('该券已发放，不允许修改面额/门槛等核心字段（仅可改名称、停发或延长有效期）');
                }
            }
            if (isset($data['valid_to']) && $coupon->valid_to && strtotime($data['valid_to']) < $coupon->valid_to->getTimestamp()) {
                throw BusinessException::conflict('已发放的券只允许延长有效期，不能缩短');
            }
        } elseif (isset($data['name']) && Coupon::where('name', $data['name'])->whereKeyNot($id)->exists()) {
            throw BusinessException::conflict('券名称已存在');
        }

        $before = $coupon->only(['name', 'type', 'amount', 'min_spend', 'total_count', 'valid_to', 'status']);
        if ($coupon->issued_count === 0) {
            $coupon->fill($this->normalize($data, $coupon));
        } else {
            // 未发放字段以原值为准；仅应用白名单
            $coupon->fill(array_intersect_key($data, array_flip(['name', 'status', 'valid_to'])));
        }
        $coupon->save();

        $this->opLog->record($request->user()?->id, 'coupon', 'update', 'Coupon', $id, [
            'before' => $before,
            'after' => $coupon->only(['name', 'type', 'amount', 'min_spend', 'total_count', 'valid_to', 'status']),
        ]);

        return $this->success(null, '更新成功');
    }

    /** 停止发放 */
    public function stop(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::find($id);
        if (! $coupon) {
            throw BusinessException::notFound('优惠券不存在');
        }
        if ($coupon->status === Coupon::STATUS_STOPPED) {
            return $this->success(null, '该券已停止发放');
        }

        $coupon->update(['status' => Coupon::STATUS_STOPPED]);
        $this->opLog->record($request->user()?->id, 'coupon', 'stop', 'Coupon', $id, ['name' => $coupon->name]);

        return $this->success(null, '已停止发放');
    }

    /** 券统计（发放/领取/核销/核销率/带来订单） */
    public function stats(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::find($id);
        if (! $coupon) {
            throw BusinessException::notFound('优惠券不存在');
        }

        $received = UserCoupon::where('coupon_id', $id)->count();
        $used = UserCoupon::where('coupon_id', $id)->where('status', UserCoupon::STATUS_USED)->count();
        $issueRate = $coupon->total_count > 0 ? round($received / $coupon->total_count, 4) : 0.0;
        $useRate = $received > 0 ? round($used / $received, 4) : 0.0;

        $orderAgg = DB::table('orders')->where('coupon_id', $id)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(discount_amount),0) as discount_sum, COALESCE(SUM(pay_amount),0) as pay_sum')
            ->first();

        return $this->success([
            'id' => $coupon->id,
            'name' => $coupon->name,
            'total_count' => $coupon->total_count,
            'issued_count' => $coupon->issued_count,
            'received_count' => $received,
            'used_count' => $used,
            'available_count' => max(0, $coupon->total_count - $coupon->issued_count),
            'issue_rate' => $issueRate,
            'use_rate' => $useRate,
            'order_count' => (int) $orderAgg->order_count,
            'discount_sum' => (float) $orderAgg->discount_sum,
            'order_amount_sum' => (float) $orderAgg->pay_sum,
        ]);
    }

    /** 领取/核销明细导出（CSV） */
    public function export(Request $request, int $id): StreamedResponse
    {
        $coupon = Coupon::find($id);
        if (! $coupon) {
            throw BusinessException::notFound('优惠券不存在');
        }

        $rows = UserCoupon::with(['user', 'order'])
            ->where('coupon_id', $id)
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $filename = 'coupon-'.$id.'-'.now()->format('YmdHis').'.csv';

        return response()->streamDownload(function () use ($rows) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['用户ID', '用户名', '状态', '领取时间', '到期时间', '使用订单号', '使用时间']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->user_id,
                    $r->user?->username,
                    UserCoupon::STATUS_LABELS[$r->status] ?? $r->status,
                    $r->created_at?->format('Y-m-d H:i:s'),
                    $r->expire_at?->format('Y-m-d H:i:s'),
                    $r->order?->order_no,
                    $r->used_at?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // ---------------- 内部 ----------------

    /** @return array<string, mixed> */
    private function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'amount' => ['required_if:type,'.Coupon::TYPE_FIXED, 'nullable', 'numeric', 'gt:0'],
            'percent' => ['required_if:type,'.Coupon::TYPE_PERCENT, 'nullable', 'integer', 'between:1,99'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'scope' => ['nullable', 'in:'.implode(',', self::SCOPES)],
            'scope_refs' => ['nullable', 'array'],
            'scope_refs.*' => ['integer', 'min:1'],
            'total_count' => ['required', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:1'],
            'valid_type' => ['required', 'in:'.implode(',', self::VALID_TYPES)],
            'valid_from' => ['required_if:valid_type,'.Coupon::VALID_ABSOLUTE, 'nullable', 'date'],
            'valid_to' => ['required_if:valid_type,'.Coupon::VALID_ABSOLUTE, 'nullable', 'date'],
            'valid_days' => ['required_if:valid_type,'.Coupon::VALID_RELATIVE, 'nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(int $id): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'in:'.implode(',', self::TYPES)],
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'percent' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'max_discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'scope' => ['sometimes', 'in:'.implode(',', self::SCOPES)],
            'scope_refs' => ['sometimes', 'nullable', 'array'],
            'scope_refs.*' => ['integer', 'min:1'],
            'total_count' => ['sometimes', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1'],
            'valid_type' => ['sometimes', 'in:'.implode(',', self::VALID_TYPES)],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'valid_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
        ];
    }

    /**
     * 归一化：fixed/percent 互斥字段、默认值、JSON 数组
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?Coupon $existing = null): array
    {
        $type = $data['type'] ?? $existing?->type ?? Coupon::TYPE_FIXED;

        $out = $data;
        $out['scope'] = $data['scope'] ?? $existing?->scope ?? Coupon::SCOPE_ALL;
        $out['scope_refs'] = $out['scope'] === Coupon::SCOPE_ALL ? [] : array_values($data['scope_refs'] ?? $existing?->scope_refs ?? []);
        $out['min_spend'] = $data['min_spend'] ?? $existing?->min_spend ?? 0;
        $out['status'] = $data['status'] ?? $existing?->status ?? Coupon::STATUS_ACTIVE;

        if ($type === Coupon::TYPE_FIXED) {
            $out['percent'] = null;
            $out['max_discount'] = null;
        } else {
            $out['amount'] = null;
            $out['max_discount'] = $data['max_discount'] ?? $existing?->max_discount ?? null;
        }

        if ($out['scope'] !== Coupon::SCOPE_ALL && empty($out['scope_refs'])) {
            throw BusinessException::badRequest('指定分类/商品范围时必须选择具体对象');
        }

        // 有效期：absolute 与 relative 字段互斥
        if (($out['valid_type'] ?? null) === Coupon::VALID_RELATIVE) {
            if (empty($out['valid_days'])) {
                throw BusinessException::badRequest('相对有效期必须填写有效天数');
            }
            $out['valid_from'] = null;
            $out['valid_to'] = null;
        } else {
            $out['valid_days'] = null;
        }

        return $out;
    }

    /** 校验 scope_refs 中的 id 是否真实存在 */
    private function assertScopeRefsValid(string $scope, array $refs): void
    {
        if ($scope === Coupon::SCOPE_ALL || $refs === []) {
            return;
        }

        $table = $scope === Coupon::SCOPE_CATEGORY ? 'categories' : 'products';
        $ids = array_values(array_unique(array_map('intval', $refs)));
        $found = DB::table($table)->whereIn('id', $ids)->pluck('id')->all();
        $missing = array_diff($ids, array_map('intval', $found));

        if ($missing !== []) {
            throw BusinessException::badRequest('适用范围包含不存在的 ID：'.implode(',', $missing));
        }
    }

    /** 绝对有效期窗口校验：valid_to 必须晚于 valid_from */
    private function assertAbsoluteWindow(array $data): void
    {
        if (empty($data['valid_from']) || empty($data['valid_to'])) {
            throw BusinessException::badRequest('绝对有效期必须同时填写起止时间');
        }
        if (strtotime($data['valid_to']) <= strtotime($data['valid_from'])) {
            throw BusinessException::badRequest('有效期结束时间必须晚于开始时间');
        }
    }

    /** @return array<string, mixed> */
    private function format(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->type,
            'type_label' => Coupon::TYPE_LABELS[$c->type] ?? $c->type,
            'amount' => $c->amount !== null ? (float) $c->amount : null,
            'percent' => $c->percent,
            'min_spend' => (float) $c->min_spend,
            'max_discount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'scope' => $c->scope,
            'scope_label' => Coupon::SCOPE_LABELS[$c->scope] ?? $c->scope,
            'scope_refs' => $c->scope_refs ?? [],
            'total_count' => $c->total_count,
            'issued_count' => $c->issued_count,
            'used_count' => $c->used_count,
            'per_user_limit' => $c->per_user_limit,
            'valid_type' => $c->valid_type,
            'valid_from' => $c->valid_from?->format('Y-m-d H:i:s'),
            'valid_to' => $c->valid_to?->format('Y-m-d H:i:s'),
            'valid_days' => $c->valid_days,
            'status' => $c->status,
            'issued' => $c->issued_count > 0,
            'created_at' => $c->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
