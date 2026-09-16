<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 满减活动管理（V1.1 二期 F06 / T-032）
 * 权限：marketing.manage
 *
 * `rules` 为多级梯度 `[{"min":100,"discount":10}, ...]`，必须**门槛递增且不重复**。
 */
class PromotionController extends Controller
{
    use ApiResponse;

    private const SCOPES = [Promotion::SCOPE_ALL, Promotion::SCOPE_CATEGORY, Promotion::SCOPE_PRODUCT];

    private const STATUSES = [Promotion::STATUS_ACTIVE, Promotion::STATUS_STOPPED];

    /** 梯度层级上限（防异常大数组） */
    private const MAX_TIERS = 20;

    public function __construct(private OperationLogService $opLog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Promotion::query()
            ->when($data['keyword'] ?? null, fn ($q, $kw) => $q->where('name', 'like', '%'.$kw.'%'))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Promotion $p) => $this->format($p));

        return $this->paginated($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->assertRules($data['rules']);
        $this->assertScopeRefsValid($data['scope'] ?? Promotion::SCOPE_ALL, $data['scope_refs'] ?? []);

        if (strtotime($data['end_at']) <= strtotime($data['start_at'])) {
            throw BusinessException::badRequest('活动结束时间必须晚于开始时间');
        }
        if (Promotion::where('name', $data['name'])->exists()) {
            throw BusinessException::conflict('活动名称已存在');
        }

        $promotion = Promotion::create([
            'name' => $data['name'],
            'rules' => $this->normalizeRules($data['rules']),
            'scope' => $data['scope'] ?? Promotion::SCOPE_ALL,
            'scope_refs' => $this->normalizeRefs($data['scope'] ?? Promotion::SCOPE_ALL, $data['scope_refs'] ?? []),
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'status' => $data['status'] ?? Promotion::STATUS_ACTIVE,
        ]);

        $this->opLog->record($request->user()?->id, 'promotion', 'create', 'Promotion', $promotion->id, [
            'name' => $promotion->name, 'tiers' => count($promotion->rules),
        ]);

        return $this->success(['id' => $promotion->id], '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::find($id);
        if (! $promotion) {
            throw BusinessException::notFound('满减活动不存在');
        }

        $data = $request->validate($this->rules(true));

        if (isset($data['rules'])) {
            $this->assertRules($data['rules']);
            $data['rules'] = $this->normalizeRules($data['rules']);
        }
        if (isset($data['scope_refs'])) {
            $scope = $data['scope'] ?? $promotion->scope;
            $this->assertScopeRefsValid($scope, $data['scope_refs']);
            $data['scope_refs'] = $this->normalizeRefs($scope, $data['scope_refs']);
        }
        $start = $data['start_at'] ?? $promotion->start_at;
        $end = $data['end_at'] ?? $promotion->end_at;
        if (strtotime((string) $end) <= strtotime((string) $start)) {
            throw BusinessException::badRequest('活动结束时间必须晚于开始时间');
        }
        if (isset($data['name']) && Promotion::where('name', $data['name'])->whereKeyNot($id)->exists()) {
            throw BusinessException::conflict('活动名称已存在');
        }

        $before = $promotion->only(['name', 'rules', 'scope', 'start_at', 'end_at', 'status']);
        $promotion->fill($data)->save();

        $this->opLog->record($request->user()?->id, 'promotion', 'update', 'Promotion', $id, [
            'before' => $before,
            'after' => $promotion->only(['name', 'rules', 'scope', 'start_at', 'end_at', 'status']),
        ]);

        return $this->success(null, '更新成功');
    }

    /** 启停 */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::find($id);
        if (! $promotion) {
            throw BusinessException::notFound('满减活动不存在');
        }

        $promotion->status = $promotion->status === Promotion::STATUS_ACTIVE
            ? Promotion::STATUS_STOPPED
            : Promotion::STATUS_ACTIVE;
        $promotion->save();

        $this->opLog->record($request->user()?->id, 'promotion', 'toggle', 'Promotion', $id, ['status' => $promotion->status]);

        return $this->success(['status' => $promotion->status], '操作成功');
    }

    // ---------------- 内部 ----------------

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:100'],
            'rules' => [$req, 'array', 'min:1', 'max:'.self::MAX_TIERS],
            'rules.*.min' => ['required', 'numeric', 'gt:0'],
            'rules.*.discount' => ['required', 'numeric', 'gt:0'],
            'scope' => ['sometimes', 'in:'.implode(',', self::SCOPES)],
            'scope_refs' => ['sometimes', 'nullable', 'array'],
            'scope_refs.*' => ['integer', 'min:1'],
            'start_at' => [$req, 'date'],
            'end_at' => [$req, 'date'],
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
        ];
    }

    /** 梯度校验：门槛严格递增（蕴含不重复） */
    private function assertRules(array $rules): void
    {
        $mins = array_map(fn ($r) => (float) $r['min'], $rules);
        $sorted = $mins;
        sort($sorted);

        if ($mins !== $sorted) {
            throw BusinessException::badRequest('满减梯度必须按门槛从小到大排列');
        }
        if (count($mins) !== count(array_unique($mins))) {
            throw BusinessException::badRequest('满减梯度门槛不能重复');
        }
    }

    /** @return array<int, array{min: float, discount: float}> */
    private function normalizeRules(array $rules): array
    {
        $out = array_map(fn ($r) => [
            'min' => round((float) $r['min'], 2),
            'discount' => round((float) $r['discount'], 2),
        ], $rules);
        usort($out, fn ($a, $b) => $a['min'] <=> $b['min']);

        return $out;
    }

    /** @return array<int, int> */
    private function normalizeRefs(string $scope, array $refs): array
    {
        return $scope === Promotion::SCOPE_ALL ? [] : array_values(array_map('intval', $refs));
    }

    private function assertScopeRefsValid(string $scope, array $refs): void
    {
        if ($scope === Promotion::SCOPE_ALL) {
            return;
        }
        if ($refs === []) {
            throw BusinessException::badRequest('指定分类/商品范围时必须选择具体对象');
        }

        $table = $scope === Promotion::SCOPE_CATEGORY ? 'categories' : 'products';
        $ids = array_values(array_unique(array_map('intval', $refs)));
        $found = array_map('intval', DB::table($table)->whereIn('id', $ids)->pluck('id')->all());
        $missing = array_diff($ids, $found);

        if ($missing !== []) {
            throw BusinessException::badRequest('适用范围包含不存在的 ID：'.implode(',', $missing));
        }
    }

    /** @return array<string, mixed> */
    private function format(Promotion $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'rules' => $p->rules ?? [],
            'scope' => $p->scope,
            'scope_refs' => $p->scope_refs ?? [],
            'start_at' => $p->start_at?->format('Y-m-d H:i:s'),
            'end_at' => $p->end_at?->format('Y-m-d H:i:s'),
            'status' => $p->status,
            'running' => $p->isRunning(),
            'created_at' => $p->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
