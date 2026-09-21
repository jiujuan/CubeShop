<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\ExpressCompany;
use App\Support\ApiResponse;
use App\Support\CarrierCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 快递公司字典维护（V1.1 T-047，E03；权限 shipping.manage）
 *
 * CRUD + 启停 + 排序 + 多渠道承运商编码 `carrier_codes`（
 * 详见 {@see CarrierCode}；`channel_code` 为快递100 的历史兼容列）。
 *
 * ⚠️ 写操作后必须清 `CarrierCode` 缓存 —— 它持有进程内的正/反查表，
 * 不清会导致本次进程（尤其长驻的队列 worker）继续用旧映射。
 * 注意静态缓存只能覆盖**当前进程**，其他进程待重启或自然失效。
 */
class ExpressCompanyController extends Controller
{
    use ApiResponse;

    /** 启用字典精简列表（发货弹窗用；GET /admin/shipping-companies/enabled，权限 order.ship） */
    public function enabled(): JsonResponse
    {
        return $this->success(
            ExpressCompany::enabled()->get(['code', 'name'])->values()->all()
        );
    }

    /** 字典列表（含停用，管理页用；GET /admin/shipping-companies?status=&keyword=） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'integer', 'in:0,1'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ExpressCompany::query()
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($w) => $w->where('code', 'like', "%{$kw}%")->orWhere('name', 'like', "%{$kw}%"));
            })
            ->orderBy('sort')
            ->orderBy('id');

        $page = $query->paginate($data['page_size'] ?? 20);

        return $this->paginated($page);
    }

    /** 新增字典（POST /admin/shipping-companies） */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (ExpressCompany::where('code', $data['code'])->exists()) {
            throw BusinessException::conflict('快递公司编码已存在');
        }

        $company = ExpressCompany::create($data);
        CarrierCode::flushCache();

        return $this->success($company, '新增成功');
    }

    /** 更新字典（PUT /admin/shipping-companies/{id}） */
    public function update(Request $request, int $id): JsonResponse
    {
        $company = ExpressCompany::find($id);
        if (! $company) {
            throw BusinessException::notFound('快递公司不存在');
        }

        $data = $this->validated($request, $company->id);
        $company->update($data);
        CarrierCode::flushCache();

        return $this->success($company->fresh(), '更新成功');
    }

    /** 删除字典（DELETE /admin/shipping-companies/{id}）：被运单引用的编码禁止删除，建议停用 */
    public function destroy(int $id): JsonResponse
    {
        $company = ExpressCompany::find($id);
        if (! $company) {
            throw BusinessException::notFound('快递公司不存在');
        }

        if ($company->shippings()->exists()) {
            throw BusinessException::conflict('该快递公司已有运单记录，不能删除（可停用）');
        }

        $company->delete();

        return $this->success(null, '删除成功');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        // 更新为部分更新语义：code/name 仅在提供时校验（新增时 required）
        $required = $ignoreId === null;

        $data = $request->validate([
            'code' => [($required ? 'required' : 'sometimes'), 'string', 'max:20', Rule::unique('express_companies', 'code')->ignore($ignoreId)],
            'name' => [($required ? 'required' : 'sometimes'), 'string', 'max:50'],
            'channel_code' => ['nullable', 'string', 'max:30'],
            'carrier_codes' => ['nullable', 'array'],
            'carrier_codes.*' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ]);

        if (array_key_exists('carrier_codes', $data)) {
            $data['carrier_codes'] = $this->normalizeCarrierCodes($data['carrier_codes']);
        }

        return $data;
    }

    /**
     * 规范化多渠道编码映射：只保留 {@see CarrierCode::CHANNELS} 声明的渠道，去空值与首尾空格。
     *
     * 非法键丢弃而非报错 —— 渠道清单以 `CarrierCode` 为准，前端多传一个键不该让整个保存失败。
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<string, string>
     */
    private function normalizeCarrierCodes(?array $raw): array
    {
        $clean = [];

        foreach (array_keys(CarrierCode::CHANNELS) as $channel) {
            $value = trim((string) ($raw[$channel] ?? ''));
            if ($value !== '') {
                $clean[$channel] = $value;
            }
        }

        return $clean;
    }
}
