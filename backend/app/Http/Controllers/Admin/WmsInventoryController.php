<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SysOperationLog;
use App\Models\WmsInventoryDiff;
use App\Models\WmsInventorySnapshot;
use App\Services\Common\OperationLogService;
use App\Services\Wms\WmsHealthCheckService;
use App\Services\Wms\WmsInventorySyncService;
use App\Services\Wms\WmsReconcileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台 WMS 库存同步 / 对账 / 健康巡检（WMS 计划 P5 / F2～F5，权限 wms.config.manage）
 *
 * 界面在 P6 补；本阶段保证「可查、可处置」：
 * - 快照与差异只读列表（带 SKU 编码，便于直接看是哪个货）；
 * - 差异处置 `resolve`（按 WMS 校准 / 忽略）落审计；
 * - 健康巡检结果直接返回（只读，不触发告警，避免刷屏）。
 *
 * ⚠️ 平台库存的写入只经 `InventoryService::adjust()`，控制器不碰 `inventories`。
 */
class WmsInventoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WmsInventorySyncService $sync,
        private readonly WmsReconcileService $reconcile,
        private readonly WmsHealthCheckService $health,
        private readonly OperationLogService $operationLog,
    ) {}

    /** GET /api/admin/wms/inventory/snapshots —— WMS 库存快照 */
    public function snapshots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WmsInventorySnapshot::query()
            ->with(['sku:id,sku_code,product_id', 'warehouse:id,code,name'])
            ->when(isset($data['warehouse_id']), fn ($q) => $q->where('warehouse_id', $data['warehouse_id']))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where('wms_sku_code', 'like', "%{$kw}%")
                    ->orWhereIn('sku_id', \App\Models\ProductSku::query()
                        ->where('sku_code', 'like', "%{$kw}%")->pluck('id'));
            })
            ->orderByDesc('synced_at');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (WmsInventorySnapshot $s) => [
            'id' => $s->id,
            'warehouse_id' => $s->warehouse_id,
            'warehouse_name' => $s->warehouse?->name,
            'sku_id' => $s->sku_id,
            'sku_code' => $s->sku?->sku_code,
            'wms_sku_code' => $s->wms_sku_code,
            'available_qty' => $s->available_qty,
            'locked_qty' => $s->locked_qty,
            'synced_at' => $s->synced_at?->toDateTimeString(),
        ]);

        return $this->paginated($page);
    }

    /** GET /api/admin/wms/inventory/diffs —— 库存差异列表 */
    public function diffs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_keys(WmsInventoryDiff::STATUS_LABELS))],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WmsInventoryDiff::query()
            ->with(['sku:id,sku_code', 'warehouse:id,code,name'])
            ->when(isset($data['warehouse_id']), fn ($q) => $q->where('warehouse_id', $data['warehouse_id']))
            ->when($data['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where('wms_sku_code', 'like', "%{$kw}%")
                    ->orWhereIn('sku_id', \App\Models\ProductSku::query()
                        ->where('sku_code', 'like', "%{$kw}%")->pluck('id'));
            })
            ->orderByRaw('ABS(diff) DESC')
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (WmsInventoryDiff $d) => [
            'id' => $d->id,
            'warehouse_id' => $d->warehouse_id,
            'warehouse_name' => $d->warehouse?->name,
            'sku_id' => $d->sku_id,
            'sku_code' => $d->sku?->sku_code,
            'wms_sku_code' => $d->wms_sku_code,
            'platform_qty' => $d->platform_qty,
            'wms_qty' => $d->wms_qty,
            'diff' => $d->diff,
            'status' => $d->status,
            'status_label' => $d->statusLabel(),
            'remark' => $d->remark,
            'handled_at' => $d->handled_at?->toDateTimeString(),
            'created_at' => $d->created_at?->toDateTimeString(),
        ]);

        return $this->paginated($page);
    }

    /**
     * POST /api/admin/wms/inventory/diffs/{id}/resolve —— 处置差异
     *
     * `action=resolve`（默认）按 WMS 值校准平台库存；`action=ignore` 只关单。
     * `apply=0` 时只关单不调库存（运营只想标记已人工核对过的场景）。
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', Rule::in(['resolve', 'ignore'])],
            'apply' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:200'],
        ]);

        $operatorId = (int) $request->user()->id;
        $action = (string) ($data['action'] ?? 'resolve');
        $remark = $data['remark'] ?? null;

        $diff = $action === 'ignore'
            ? $this->reconcile->ignore($id, $operatorId, $remark)
            : $this->reconcile->resolve($id, $operatorId, (bool) ($data['apply'] ?? true), $remark);

        // 服务层已写处置审计；这里补一条「谁在后台点了什么」的接口级留痕
        $this->operationLog->record(
            $operatorId,
            'wms',
            'inventory_diff_handle',
            'wms_inventory_diff',
            $id,
            ['action' => $action, 'apply' => (bool) ($data['apply'] ?? true), 'remark' => $remark],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $this->success([
            'id' => $diff->id,
            'status' => $diff->status,
            'status_label' => $diff->statusLabel(),
        ]);
    }

    /** GET /api/admin/wms/health —— 健康巡检（只读，不触发告警） */
    public function health(): JsonResponse
    {
        $report = $this->health->collect();

        return $this->success([
            'checked_at' => $report['checked_at'],
            'healthy' => $report['healthy'],
            'summary' => $report['summary'],
            'checks' => $report['checks'],
        ]);
    }

    /** POST /api/admin/wms/inventory/sync —— 手工触发一次同步（默认只写快照） */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'apply' => ['nullable', 'boolean'],
        ]);

        $apply = (bool) ($data['apply'] ?? false);
        $operatorId = (int) $request->user()->id;

        $configs = isset($data['warehouse_id'])
            ? \App\Models\WmsConfig::query()->where('warehouse_id', $data['warehouse_id'])->get()
            : $this->sync->enabledConfigs();

        $summary = [];
        foreach ($configs as $config) {
            $summary[] = [
                'warehouse_id' => (int) $config->warehouse_id,
                'synced' => $this->sync->syncWarehouse($config, [], $apply),
            ];
        }

        $this->operationLog->record(
            $operatorId,
            'wms',
            'inventory_sync_manual',
            'wms',
            $data['warehouse_id'] ?? null,
            ['apply' => $apply, 'summary' => $summary, 'errors' => $this->sync->errors()],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $this->success([
            'summary' => $summary,
            'errors' => $this->sync->errors(),
        ]);
    }
}
