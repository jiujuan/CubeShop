<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FreightTemplate;
use App\Services\Shipping\FreightRuleValidator;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台运费模板管理（T-053 Stage1，权限 shipping.manage）
 *
 * 写操作记 sys_operation_log（module=freight_template）。
 * 规则 fail-closed：rules 结构非法 / region 省 code 非法 → 422，绝不带坏规则入库。
 *
 * Stage4 修复：新增「设为全局默认」——把模板 id 写入配置 order.freight_template_id
 * （未绑定模板的商品行走该模板）。此前模板建好后无任何引用时引擎永远走旧口径，
 * 运营误以为规则已生效（详见 2026-09-18 结算页运费与后台 region 模板不一致事故）。
 */
class FreightTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(private \App\Services\Common\ConfigService $config)
    {
    }

    /** GET /api/admin/freight-templates —— 列表（筛选 + 分页） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:50'],
            'mode' => ['nullable', 'string', 'in:fixed,weight,region'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = FreightTemplate::query()
            ->when(! empty($data['keyword']), fn ($q) => $q->where('name', 'like', '%'.$data['keyword'].'%'))
            ->when(! empty($data['mode']), fn ($q) => $q->where('mode', $data['mode']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', (int) $data['status']))
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        $list = collect($paginator->items())->map(fn (FreightTemplate $t) => $this->toArray($t))->all();

        return $this->success([
            'list' => $list,
            /** 当前全局默认模板 id（0=无，走旧口径固定运费）；列表页展示徽标与操作用 */
            'default_id' => $this->config->getInt('order.freight_template_id', 0),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /** POST /api/admin/freight-templates —— 新建 */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $template = FreightTemplate::create($data);
        $this->log($request, 'freight_template_create', 'freight_templates', $template->id, '创建运费模板 '.$template->name);

        return $this->success($this->toArray($template), '已创建', 201);
    }

    /** PUT /api/admin/freight-templates/{id} —— 编辑 */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = FreightTemplate::findOrFail($id);
        $data = $this->validated($request, sometimes: true, fallbackMode: $template->mode);

        $template->update($data);
        $this->log($request, 'freight_template_update', 'freight_templates', $id, '编辑运费模板 '.$template->name);

        return $this->success($this->toArray($template), '已更新');
    }

    /** POST /api/admin/freight-templates/{id}/set-default —— 设为全局默认（order.freight_template_id） */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $template = FreightTemplate::findOrFail($id);

        if ($template->status !== 1) {
            throw \App\Exceptions\BusinessException::badRequest('停用的模板不能设为全局默认，请先启用');
        }

        $this->config->set('order.freight_template_id', (string) $template->id);
        $this->log($request, 'freight_template_set_default', 'freight_templates', $id, '设为全局默认运费模板 '.$template->name);

        return $this->success(['default_id' => $template->id], '已设为全局默认模板');
    }

    /** POST /api/admin/freight-templates/clear-default —— 取消全局默认（回到旧口径固定运费） */
    public function clearDefault(Request $request): JsonResponse
    {
        $current = $this->config->getInt('order.freight_template_id', 0);
        $this->config->set('order.freight_template_id', '');
        $this->log($request, 'freight_template_clear_default', 'freight_templates', $current, '取消全局默认运费模板（回到固定运费口径）');

        return $this->success(['default_id' => 0], '已取消全局默认模板');
    }

    /** DELETE /api/admin/freight-templates/{id} —— 删除（仍有商品绑定时拒绝，fail-closed） */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $template = FreightTemplate::findOrFail($id);

        $boundCount = \App\Models\Product::withTrashed()->where('freight_template_id', $id)->count();
        if ($boundCount > 0) {
            throw \App\Exceptions\BusinessException::conflict(
                "仍有 {$boundCount} 个商品绑定该运费模板，请先在商品编辑中解绑或改绑后再删除"
            );
        }

        $template->delete();
        $this->log($request, 'freight_template_delete', 'freight_templates', $id, '删除运费模板 '.$template->name);

        return $this->success(null, '已删除');
    }

    /**
     * 公共入参校验：基础字段 + FreightRuleValidator 规则校验（422 fail-closed）
     *
     * @param  string|null  $fallbackMode  编辑时未传 mode 则沿用模板既有 mode
     * @return array{name: string, mode: string, rules: array, status: int}
     */
    private function validated(Request $request, bool $sometimes = false, ?string $fallbackMode = null): array
    {
        $base = [
            'name' => [($sometimes ? 'sometimes' : 'required'), 'string', 'max:64'],
            'mode' => [($sometimes ? 'sometimes' : 'required'), 'string', 'in:fixed,weight,region'],
            'rules' => [($sometimes ? 'sometimes' : 'required'), 'array'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ];
        $data = $request->validate($base);

        if (array_key_exists('rules', $data)) {
            $mode = $data['mode'] ?? $fallbackMode ?? '';
            $errors = FreightRuleValidator::validate((string) $mode, $data['rules']);
            if ($errors !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages(['rules' => $errors]);
            }
        }

        $data['status'] = (int) ($data['status'] ?? 1);

        return $data;
    }

    private function toArray(FreightTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'mode' => $t->mode,
            'mode_label' => $t->modeLabel(),
            'rules' => $t->rules,
            'status' => (int) $t->status,
            'created_at' => $t->created_at?->toDateTimeString(),
            'updated_at' => $t->updated_at?->toDateTimeString(),
        ];
    }

    private function log(Request $request, string $action, string $targetType, int $targetId, string $content): void
    {
        \App\Models\SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => \App\Models\SysOperationLog::ACTOR_ADMIN,
            'module' => 'freight_template',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content' => $content,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
