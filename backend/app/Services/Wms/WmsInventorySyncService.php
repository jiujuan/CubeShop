<?php

namespace App\Services\Wms;

use App\Models\ProductSku;
use App\Models\SysOperationLog;
use App\Models\WmsApiLog;
use App\Models\WmsConfig;
use App\Models\WmsInventorySnapshot;
use App\Services\Common\OperationLogService;
use App\Services\Inventory\InventoryService;
use App\Services\Wms\Dto\InventoryQueryDto;
use App\Services\Wms\Dto\WmsResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WMS 库存同步（WMS 计划 P5 / F2）
 *
 * 职责：按仓库分页拉取 WMS 可用库存 → 写 `wms_inventory_snapshots`。
 *
 * ⚠️ **默认绝不改平台可售库存**（设计 §12 库存不一致风险）：
 * WMS 侧数字可能脏（在途未回传、盘点未同步、残次未隔离），直接拿来覆盖
 * `inventories` 会制造超卖或凭空多卖。差异只落到快照，由对账任务暴露、人工校准。
 * 只有显式 `apply=true`（命令 `--apply` / 后台「按 WMS 校准」）才动库存，
 * 且每笔调整都走 `InventoryService::adjust()` 带 `wms_sync` 备注留痕。
 *
 * 失败语义：单批查询失败**跳过该批**并继续（一个仓的临时抖动不该拖垮全量同步），
 * 全部失败也不抛——定时任务抛异常只会刷屏，问题由 `wms_api_logs` + 健康巡检暴露。
 */
class WmsInventorySyncService
{
    /** 库存调整备注（对账校准与同步校准共用前缀，便于 inventory_logs 检索） */
    public const REMARK_SYNC = 'WMS 库存同步校准';

    /**
     * @var list<string> 最近一次同步的批次级错误（命令输出用）
     */
    private array $errors = [];

    public function __construct(
        private readonly WmsAdapterFactory $factory,
        private readonly WmsConfigService $configs,
        private readonly WmsApiLogService $apiLogs,
        private readonly InventoryService $inventory,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 同步一个仓库的库存快照。
     *
     * @param  array<int, int>  $skuIds  指定 SKU（空 = 全量）
     * @return int 写入/更新的快照条数
     */
    public function syncWarehouse(WmsConfig $config, array $skuIds = [], bool $apply = false): int
    {
        $this->errors = [];

        if (! $config->enabled) {
            $this->errors[] = sprintf('仓库 #%d 未启用 WMS，跳过', $config->warehouse_id);

            return 0;
        }

        $adapter = $this->factory->make($config);
        $pageSize = max(1, (int) config('wms.inventory.page_size', 100));
        $synced = 0;
        $adjusted = 0;

        $query = ProductSku::query()
            ->when($skuIds !== [], fn ($q) => $q->whereIn('id', $skuIds))
            ->orderBy('id');

        // chunkById 而非一次性 pluck：SKU 量大时不把整张表读进内存
        $query->chunkById($pageSize, function ($skus) use ($config, $adapter, $apply, &$synced, &$adjusted) {
            // skuId => wmsCode：manual 模式未映射的 SKU 跳过（不推也不能推错货，同 P1 口径）
            $codeMap = [];
            foreach ($skus as $sku) {
                try {
                    $codeMap[$sku->id] = $this->configs->resolveSkuCode($config, (int) $sku->id);
                } catch (Throwable $e) {
                    $this->errors[] = sprintf('SKU #%d 解析 WMS 编码失败：%s', $sku->id, $e->getMessage());
                }
            }

            if ($codeMap === []) {
                return;
            }

            $started = microtime(true);
            $dto = new InventoryQueryDto(
                (int) $config->warehouse_id,
                array_values(array_unique($codeMap)),
                'inventory-sync',
            );

            try {
                $result = $adapter->queryInventory($dto);
            } catch (Throwable $e) {
                $result = WmsResult::fail($e->getMessage(), 500);
            }

            $this->apiLogs->record(
                $config,
                'queryInventory',
                $result,
                $this->apiLogs->elapsedMs($started),
                $result->data['request_id'] ?? null,
                'inventory-sync',
                ['warehouse_id' => $config->warehouse_id, 'sku_codes' => $dto->skuCodes],
            );

            if (! $result->success) {
                $this->errors[] = sprintf(
                    '第 %d～%d 号 SKU 查询失败：%s',
                    array_key_first($codeMap), array_key_last($codeMap), (string) $result->error,
                );

                return;
            }

            $quantities = $this->quantitiesFrom($result);

            foreach ($codeMap as $skuId => $code) {
                if (! array_key_exists($code, $quantities)) {
                    continue; // 对方没返回这个货品：不写 0，避免「未同步」被误读成「无货」
                }

                $available = (int) $quantities[$code];
                WmsInventorySnapshot::query()->updateOrCreate(
                    ['warehouse_id' => $config->warehouse_id, 'sku_id' => $skuId],
                    [
                        'wms_sku_code' => $code,
                        'available_qty' => $available,
                        'locked_qty' => (int) ($result->data['locked'][$code] ?? 0),
                        'synced_at' => now(),
                    ],
                );
                $synced++;

                if ($apply) {
                    $adjusted += $this->applyToPlatform((int) $skuId, $available);
                }
            }
        });

        $this->operationLog->record(
            null,
            'wms',
            'inventory_sync',
            'warehouse',
            (int) $config->warehouse_id,
            [
                'synced' => $synced,
                'apply' => $apply,
                'adjusted' => $adjusted,
                'errors' => $this->errors,
            ],
            SysOperationLog::ACTOR_ADMIN,
        );

        return $synced;
    }

    /**
     * 同步所有启用仓库。
     *
     * @return array<int, int> warehouseId => 快照条数
     */
    public function syncAll(array $skuIds = [], bool $apply = false): array
    {
        $summary = [];

        foreach ($this->enabledConfigs() as $config) {
            $summary[(int) $config->warehouse_id] = $this->syncWarehouse($config, $skuIds, $apply);
        }

        return $summary;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return \Illuminate\Support\Collection<int, WmsConfig> */
    public function enabledConfigs()
    {
        return WmsConfig::query()->where('enabled', true)->orderBy('warehouse_id')->get();
    }

    // ---------------- 内部 ----------------

    /**
     * 归一「货品编码 => 可用量」。
     *
     * 各 Adapter 返回形状不一：菜鸟给 `quantities` + `items`，Mock 只给 `items`，
     * 这里统一成一张 code => qty 的表，调用方不再关心报文细节。
     *
     * @return array<string, int>
     */
    private function quantitiesFrom(WmsResult $result): array
    {
        $data = $result->data;
        $map = [];

        if (isset($data['quantities']) && is_array($data['quantities'])) {
            foreach ($data['quantities'] as $code => $qty) {
                $map[(string) $code] = (int) $qty;
            }
        }

        foreach ((array) ($data['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['sku_code'] ?? $row['itemCode'] ?? '');
            if ($code === '') {
                continue;
            }
            // quantities 已给过的以 quantities 为准（避免 items 缺字段把值冲成 0）
            $map[$code] ??= (int) ($row['quantity'] ?? $row['availableQty'] ?? 0);
        }

        return $map;
    }

    /**
     * 把平台库存校准到 WMS 值（仅 `--apply` / 后台校准走这里）。
     *
     * @return int 1 = 实际调整，0 = 无需调整或调整失败
     */
    private function applyToPlatform(int $skuId, int $wmsQty): int
    {
        $platformQty = (int) DB::table('inventories')->where('sku_id', $skuId)->value('stock');
        $delta = $wmsQty - $platformQty;

        if ($delta === 0) {
            return 0;
        }

        try {
            $this->inventory->adjust($skuId, $delta, null, self::REMARK_SYNC, 'wms_sync');
        } catch (Throwable $e) {
            // 库存不足（delta<0 且平台量更小）等：记录后继续，不中断整批同步
            $this->errors[] = sprintf('SKU #%d 校准失败：%s', $skuId, $e->getMessage());

            return 0;
        }

        return 1;
    }
}
