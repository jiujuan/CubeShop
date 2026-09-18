<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\ProductSku;
use App\Models\SysOperationLog;
use App\Models\Warehouse;
use App\Models\WmsConfig;
use App\Models\WmsSkuMapping;
use App\Services\Common\OperationLogService;
use App\Services\Wms\WmsConfigService;
use App\Support\ApiResponse;
use App\Support\PublicId;
use App\Support\WmsMappingMode;
use App\Support\WmsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 后台 WMS 对接配置（WMS 计划 P0 / F1～F8，权限 wms.config.manage）
 *
 * 覆盖：仓库档案 CRUD、按仓库唯一配置读写、连通性测试、SKU 映射管理（列表 / 批量导入 / 删除）。
 *
 * 安全约定（SEC-01 / D6）：
 * - `app_secret` / `access_token` **只写不读**，出口一律 `masked`（`****` + 末 4 位）；
 * - `callback_token` 只在回调 URL 中出现，不单独回传；
 * - 写操作全部落 `sys_operation_log`（module=wms）。
 */
class WmsConfigController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WmsConfigService $configs,
        private readonly OperationLogService $operationLog,
    ) {}

    // ==================== 仓库档案 ====================

    /** GET /api/admin/wms/warehouses —— 仓库列表（含 WMS 配置摘要） */
    public function warehouses(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Warehouse::query()
            ->with('wmsConfig')
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($w) => $w->where('code', 'like', "%{$kw}%")->orWhere('name', 'like', "%{$kw}%"));
            })
            ->orderBy('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (Warehouse $w) => $this->warehousePayload($w));

        return $this->paginated($page);
    }

    /** POST /api/admin/wms/warehouses —— 新建仓库 */
    public function storeWarehouse(Request $request): JsonResponse
    {
        $data = $this->validateWarehouse($request);

        if (Warehouse::where('code', $data['code'])->exists()) {
            throw BusinessException::conflict('仓库编码已存在');
        }

        $warehouse = Warehouse::create($data);

        $this->operationLog->record(
            $request->user()->id, 'wms', 'warehouse_created', 'warehouse', $warehouse->id,
            ['code' => $warehouse->code, 'name' => $warehouse->name],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $this->success($this->warehousePayload($warehouse->load('wmsConfig')), '新增成功');
    }

    /** PUT /api/admin/wms/warehouses/{id} —— 更新仓库 */
    public function updateWarehouse(Request $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::find($id);
        if (! $warehouse) {
            throw BusinessException::notFound('仓库不存在');
        }

        $data = $this->validateWarehouse($request);

        $codeTaken = Warehouse::where('code', $data['code'])->where('id', '!=', $warehouse->id)->exists();
        if ($codeTaken) {
            throw BusinessException::conflict('仓库编码已存在');
        }

        $warehouse->update($data);

        $this->operationLog->record(
            $request->user()->id, 'wms', 'warehouse_updated', 'warehouse', $warehouse->id,
            ['code' => $warehouse->code, 'name' => $warehouse->name],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $this->success($this->warehousePayload($warehouse->fresh()->load('wmsConfig')), '更新成功');
    }

    // ==================== WMS 配置 ====================

    /** GET /api/admin/wms/warehouses/{id}/config —— 读取配置（凭证掩码；未配置返回默认值） */
    public function showConfig(int $id): JsonResponse
    {
        $warehouse = Warehouse::find($id);
        if (! $warehouse) {
            throw BusinessException::notFound('仓库不存在');
        }

        $config = $this->configs->getForWarehouse($id);

        return $this->success($config
            ? $this->configPayload($config)
            : $this->defaultConfigPayload($warehouse));
    }

    /** PUT /api/admin/wms/warehouses/{id}/config —— 保存配置（凭证留空 = 不覆盖） */
    public function saveConfig(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(WmsProvider::ALL))],
            'enabled' => ['required', 'boolean'],
            'auto_push' => ['required', 'boolean'],
            'auto_push_return' => ['required', 'boolean'],
            'push_retry_times' => ['required', 'integer', 'min:0', 'max:10'],
            'sku_mapping_mode' => ['required', 'string', Rule::in(array_keys(WmsMappingMode::ALL))],
            'app_key' => ['nullable', 'string', 'max:64'],
            'app_secret' => ['nullable', 'string', 'max:128'],
            'access_token' => ['nullable', 'string', 'max:512'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'owner_no' => ['nullable', 'string', 'max:64'],
            'warehouse_code' => ['nullable', 'string', 'max:64'],
            'warehouse_no' => ['nullable', 'string', 'max:64'],
            'api_env' => ['required', 'string', Rule::in(['prod', 'sandbox'])],
            'extra_config' => ['nullable', 'array'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $config = $this->configs->save($id, $data, $request->user()->id);

        return $this->success($this->configPayload($config), '保存成功');
    }

    /** POST /api/admin/wms/warehouses/{id}/config/test —— 连通性测试（Mock 亦可） */
    public function testConfig(Request $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::find($id);
        if (! $warehouse) {
            throw BusinessException::notFound('仓库不存在');
        }

        $config = $this->configs->getForWarehouse($id);
        if (! $config) {
            throw BusinessException::notFound('该仓库尚未配置 WMS，请先保存配置');
        }

        return $this->success($this->configs->testConnection($config));
    }

    // ==================== SKU 映射 ====================

    /** GET /api/admin/wms/warehouses/{id}/sku-mappings —— 映射列表（含商品信息） */
    public function skuMappings(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! Warehouse::whereKey($id)->exists()) {
            throw BusinessException::notFound('仓库不存在');
        }

        $query = WmsSkuMapping::query()
            ->where('warehouse_id', $id)
            ->with('sku.product')
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($w) => $w
                    ->where('platform_sku_code', 'like', "%{$kw}%")
                    ->orWhere('wms_sku_code', 'like', "%{$kw}%")
                    ->orWhere('barcode', 'like', "%{$kw}%"));
            })
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 20));
        $page->through(fn (WmsSkuMapping $m) => $this->mappingPayload($m));

        return $this->paginated($page);
    }

    /**
     * POST /api/admin/wms/warehouses/{id}/sku-mappings/batch —— 批量导入/新增映射
     *
     * 逐行处理、**不整体回滚**：某行 sku_code 不存在或字段非法时只跳过该行并回传行号，
     * 便于运营按提示修正后重传（整批回滚会让正确行反复重做）。
     */
    public function importSkuMappings(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.sku_code' => ['required', 'string', 'max:64'],
            'rows.*.wms_sku_code' => ['required', 'string', 'max:64'],
            'rows.*.barcode' => ['nullable', 'string', 'max:64'],
        ]);

        if (! Warehouse::whereKey($id)->exists()) {
            throw BusinessException::notFound('仓库不存在');
        }

        $results = [];
        $successCount = 0;

        foreach ($data['rows'] as $index => $row) {
            $line = $index + 1;
            $skuCode = trim((string) $row['sku_code']);
            $wmsSkuCode = trim((string) $row['wms_sku_code']);
            $barcode = isset($row['barcode']) ? trim((string) $row['barcode']) : null;

            $sku = ProductSku::where('sku_code', $skuCode)->first();
            if (! $sku) {
                $results[] = ['line' => $line, 'sku_code' => $skuCode, 'success' => false, 'message' => '平台 SKU 编码不存在'];

                continue;
            }

            DB::transaction(function () use ($id, $sku, $skuCode, $wmsSkuCode, $barcode) {
                WmsSkuMapping::updateOrCreate(
                    ['warehouse_id' => $id, 'sku_id' => $sku->id],
                    [
                        'platform_sku_code' => $skuCode,
                        'wms_sku_code' => $wmsSkuCode,
                        'barcode' => $barcode ?: null,
                        'status' => 1,
                    ],
                );
            });

            $successCount++;
            $results[] = ['line' => $line, 'sku_code' => $skuCode, 'success' => true, 'message' => '导入成功'];
        }

        $this->operationLog->record(
            $request->user()->id, 'wms', 'sku_mapping_imported', 'warehouse', $id,
            ['total' => count($data['rows']), 'success' => $successCount, 'failed' => count($data['rows']) - $successCount],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $this->success([
            'total' => count($data['rows']),
            'success_count' => $successCount,
            'failed_count' => count($data['rows']) - $successCount,
            'results' => $results,
        ], '导入完成');
    }

    /** DELETE /api/admin/wms/warehouses/{id}/sku-mappings/{skuId} —— 删除映射（skuId 支持 public_id） */
    public function destroySkuMapping(Request $request, int $id, string $skuId): JsonResponse
    {
        $resolved = PublicId::resolve(PublicId::SCOPE_SKU, $skuId) ?? (ctype_digit($skuId) ? (int) $skuId : null);
        if (! $resolved) {
            throw BusinessException::notFound('SKU 不存在');
        }

        $mapping = WmsSkuMapping::where('warehouse_id', $id)->where('sku_id', $resolved)->first();
        if (! $mapping) {
            throw BusinessException::notFound('映射不存在');
        }

        $platformCode = $mapping->platform_sku_code;
        $mapping->delete();

        $this->operationLog->record(
            $request->user()->id, 'wms', 'sku_mapping_deleted', 'warehouse', $id,
            ['platform_sku_code' => $platformCode],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $this->success(null, '删除成功');
    }

    // ==================== 内部 ====================

    /** @return array<string, mixed> */
    private function validateWarehouse(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:32'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'province' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:32'],
            'district' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:200'],
            'status' => ['required', 'integer', 'in:0,1'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function warehousePayload(Warehouse $warehouse): array
    {
        $config = $warehouse->relationLoaded('wmsConfig') ? $warehouse->wmsConfig : null;

        return [
            'id' => $warehouse->id,
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'contact_name' => $warehouse->contact_name,
            'contact_phone' => $warehouse->contact_phone,
            'province' => $warehouse->province,
            'city' => $warehouse->city,
            'district' => $warehouse->district,
            'address' => $warehouse->address,
            'status' => (int) $warehouse->status,
            'created_at' => $warehouse->created_at?->format('Y-m-d H:i:s'),
            // 列表页需要用「一句摘要」判断哪些仓还没接 WMS
            'wms' => $config ? [
                'provider' => $config->provider,
                'provider_label' => WmsProvider::label($config->provider),
                'enabled' => (bool) $config->enabled,
                'api_env' => $config->api_env,
                'sku_mapping_mode' => $config->sku_mapping_mode,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function configPayload(WmsConfig $config): array
    {
        return [
            'configured' => true,
            'id' => $config->id,
            'warehouse_id' => (int) $config->warehouse_id,
            'provider' => $config->provider,
            'provider_label' => WmsProvider::label((string) $config->provider),
            'enabled' => (bool) $config->enabled,
            'auto_push' => (bool) $config->auto_push,
            'auto_push_return' => (bool) $config->auto_push_return,
            'push_retry_times' => (int) $config->push_retry_times,
            'sku_mapping_mode' => $config->sku_mapping_mode,
            'app_key' => $config->app_key,
            // 只读掩码：明文永不出接口
            'app_secret_masked' => $config->maskedAppSecret(),
            'has_app_secret' => $config->hasAppSecret(),
            'access_token_masked' => $config->maskedAccessToken(),
            'has_access_token' => $config->hasAccessToken(),
            'customer_id' => $config->customer_id,
            'owner_no' => $config->owner_no,
            'warehouse_code' => $config->warehouse_code,
            'warehouse_no' => $config->warehouse_no,
            'api_env' => $config->api_env,
            'extra_config' => $config->extra_config,
            'remark' => $config->remark,
            'callback_url' => $this->configs->callbackUrl($config),
            'updated_at' => $config->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 未配置时的默认回显（前端直接可用，不必自己拼默认值） */
    private function defaultConfigPayload(Warehouse $warehouse): array
    {
        return [
            'configured' => false,
            'id' => null,
            'warehouse_id' => (int) $warehouse->id,
            'provider' => WmsProvider::CAINIAO,
            'provider_label' => WmsProvider::label(WmsProvider::CAINIAO),
            'enabled' => false,
            'auto_push' => true,
            'auto_push_return' => true,
            'push_retry_times' => 3,
            'sku_mapping_mode' => WmsMappingMode::SAME,
            'app_key' => null,
            'app_secret_masked' => null,
            'has_app_secret' => false,
            'access_token_masked' => null,
            'has_access_token' => false,
            'customer_id' => null,
            'owner_no' => null,
            'warehouse_code' => null,
            'warehouse_no' => null,
            'api_env' => 'sandbox',
            'extra_config' => null,
            'remark' => null,
            'callback_url' => null,
            'updated_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function mappingPayload(WmsSkuMapping $mapping): array
    {
        $sku = $mapping->sku;

        return [
            'id' => $mapping->id,
            'warehouse_id' => (int) $mapping->warehouse_id,
            'sku_id' => $sku?->public_id,
            'platform_sku_code' => $mapping->platform_sku_code,
            'wms_sku_code' => $mapping->wms_sku_code,
            'barcode' => $mapping->barcode,
            'status' => (int) $mapping->status,
            // 运营核对用：映射到哪个商品/规格一眼可见
            'product_title' => $sku?->product?->title,
            'sku_specs' => $sku?->specs,
            'updated_at' => $mapping->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
