<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use App\Models\ProductSku;
use App\Services\Common\OperationLogService;
use App\Services\Product\ProductAttributeService;
use Illuminate\Support\Facades\DB;

/**
 * 库存盘点
 *
 * 流程：建单（圈选范围 → 生成明细 + 账面快照）→ 录入实盘（页面/Excel）→ 过账 → 完成。
 *
 * 三条硬规则（详见 docs/design/product-import-and-inventory-check.md）：
 * 1. 差异以**过账时刻的实时库存**为准，开单快照 system_qty 只用于展示；
 * 2. 调账必须走 InventoryService::adjust()，流水带 biz_type=inventory_check + biz_id=盘点单ID 可反查；
 * 3. 不能把已锁定（未支付订单占用）的库存盘掉：stock + delta < locked_stock 的行标 skipped 并告警。
 *
 * 过账是终态且幂等：状态守卫 + 行锁，重复点击只会得到冲突提示。
 */
class InventoryCheckService
{
    /** 单张盘点单的明细上限，防止全量盘点时一次塞爆 */
    public const MAX_ITEMS = 5000;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 建单并生成明细（含账面快照）
     *
     * @param  array{title?: string|null, scope_type: string, scope_value?: string|null, remark?: string|null}  $data
     * @param  array<int, string>  $skuCodes  custom 模式下的 SKU 编码清单
     */
    public function create(array $data, int $adminId, array $skuCodes = []): InventoryCheck
    {
        $scopeType = $data['scope_type'] ?? InventoryCheck::SCOPE_ALL;
        $scopeValue = $data['scope_value'] ?? null;

        $skuIds = $this->resolveSkuIds($scopeType, $scopeValue, $skuCodes);

        if ($skuIds === []) {
            throw BusinessException::badRequest('该范围内没有可盘点的 SKU');
        }
        if (count($skuIds) > self::MAX_ITEMS) {
            throw BusinessException::badRequest(sprintf('该范围共 %d 个 SKU，超过单张盘点单上限 %d，请缩小范围', count($skuIds), self::MAX_ITEMS));
        }

        return DB::transaction(function () use ($data, $adminId, $scopeType, $scopeValue, $skuIds): InventoryCheck {
            $check = InventoryCheck::create([
                'check_no' => $this->nextCheckNo(),
                'title' => $data['title'] ?? null,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'status' => InventoryCheck::STATUS_DRAFT,
                'remark' => $data['remark'] ?? null,
                'created_by' => $adminId,
            ]);

            $this->createItems($check, $skuIds);

            $check->item_count = count($skuIds);
            $check->save();

            return $check;
        });
    }

    /**
     * 录入实盘（单行或批量）
     *
     * @param  array<int, array{item_id: int, counted_qty: int, remark?: string|null}>  $items
     * @return array{updated: int}
     */
    public function record(InventoryCheck $check, array $items, int $adminId): array
    {
        $this->assertOpen($check);

        $updated = DB::transaction(function () use ($check, $items): int {
            $map = $check->items()->whereIn('id', array_column($items, 'item_id'))->get()->keyBy('id');
            $count = 0;

            foreach ($items as $item) {
                $model = $map->get((int) $item['item_id']);
                if (! $model) {
                    continue;
                }
                $model->counted_qty = max(0, (int) $item['counted_qty']);
                $model->remark = $item['remark'] ?? $model->remark;
                $model->status = InventoryCheckItem::STATUS_COUNTED;
                $model->save();
                $count++;
            }

            $this->refreshStats($check);

            return $count;
        });

        $this->operationLog->record($adminId, 'inventory', 'check_count', 'inventory_check', $check->id, [
            'check_no' => $check->check_no,
            'updated' => $updated,
        ]);

        return ['updated' => $updated];
    }

    /**
     * Excel 批量回填实盘（预校验通过才写入）
     *
     * @param  array<int, array<int, mixed>>  $rows  每行：SKU编码 / 实盘数量 / 备注
     * @return array{updated: int, failed: array<int, array<string, mixed>>}
     */
    public function importCounted(InventoryCheck $check, array $rows): array
    {
        $this->assertOpen($check);

        $failed = [];
        $updates = [];
        $seen = [];

        $codes = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) ($r[0] ?? '')), $rows))));
        $map = $codes === []
            ? collect()
            : $check->items()->whereIn('sku_code', $codes)->get()->keyBy('sku_code');

        foreach ($rows as $i => $row) {
            $code = trim((string) ($row[0] ?? ''));
            $line = $i + 2;

            if ($code === '') {
                $failed[] = ['row' => $line, 'sku_code' => '', 'reason' => 'SKU编码不能为空'];
                continue;
            }
            if (! $map->has($code)) {
                $failed[] = ['row' => $line, 'sku_code' => $code, 'reason' => '该 SKU 不在本盘点单内'];
                continue;
            }
            if (isset($seen[$code])) {
                $failed[] = ['row' => $line, 'sku_code' => $code, 'reason' => 'SKU编码在本次文件中重复'];
                continue;
            }

            $qty = trim((string) ($row[1] ?? ''));
            if ($qty === '' || ! ctype_digit($qty)) {
                $failed[] = ['row' => $line, 'sku_code' => $code, 'reason' => '实盘数量必须是 ≥0 的整数'];
                continue;
            }

            $seen[$code] = $line;
            $updates[] = [
                'item' => $map->get($code),
                'qty' => (int) $qty,
                'remark' => trim((string) ($row[2] ?? '')),
            ];
        }

        if ($failed !== []) {
            return ['updated' => 0, 'failed' => $failed];
        }

        DB::transaction(function () use ($check, $updates): void {
            foreach ($updates as $u) {
                $u['item']->counted_qty = $u['qty'];
                $u['item']->remark = $u['remark'] !== '' ? $u['remark'] : $u['item']->remark;
                $u['item']->status = InventoryCheckItem::STATUS_COUNTED;
                $u['item']->save();
            }
            $this->refreshStats($check);
        });

        return ['updated' => count($updates), 'failed' => []];
    }

    /**
     * 过账：按「实盘 - 过账时刻实时库存」调账
     *
     * @return array{adjusted: int, skipped: int, unchanged: int, total_diff_qty: int}
     */
    public function post(InventoryCheck $check, int $adminId): array
    {
        return DB::transaction(function () use ($check, $adminId): array {
            // 幂等核心：锁行 + 状态守卫，重复过账只会拿到冲突提示
            $fresh = InventoryCheck::where('id', $check->id)->lockForUpdate()->first();
            if ($fresh->status === InventoryCheck::STATUS_POSTED) {
                throw BusinessException::conflict('该盘点单已过账，请勿重复操作');
            }
            if ($fresh->status === InventoryCheck::STATUS_CANCELLED) {
                throw BusinessException::conflict('该盘点单已作废，无法过账');
            }

            $adjusted = 0;
            $skipped = 0;
            $unchanged = 0;

            $items = $fresh->items()->whereNotNull('counted_qty')->orderBy('id')->lockForUpdate()->get();

            foreach ($items as $item) {
                $inventory = Inventory::where('sku_id', $item->sku_id)->lockForUpdate()->first();
                $stock = $inventory?->stock ?? 0;
                $locked = $inventory?->locked_stock ?? 0;
                $delta = $item->counted_qty - $stock;

                if ($delta === 0) {
                    $item->diff_qty = 0;
                    $item->status = InventoryCheckItem::STATUS_POSTED;
                    $item->save();
                    $unchanged++;
                    continue;
                }

                // 关键守卫：不能把已锁定给未支付订单的库存盘成负数可用
                if ($delta < 0 && $stock + $delta < $locked) {
                    $item->status = InventoryCheckItem::STATUS_SKIPPED;
                    $item->remark = sprintf('可用库存不足：账面 %d、锁定 %d，无法扣减 %d', $stock, $locked, -$delta);
                    $item->save();
                    $skipped++;
                    continue;
                }

                $this->inventory->adjust(
                    $item->sku_id,
                    $delta,
                    $adminId,
                    sprintf('盘点单 %s 过账', $fresh->check_no),
                    'inventory_check',
                    $fresh->id,
                );

                $item->diff_qty = $delta;
                $item->status = InventoryCheckItem::STATUS_POSTED;
                $item->save();
                $adjusted++;
            }

            // 未录入的行保持 pending，不计入统计
            $fresh->items()->whereNull('counted_qty')->update(['status' => InventoryCheckItem::STATUS_PENDING]);

            $this->refreshStats($fresh, true);

            $fresh->status = InventoryCheck::STATUS_POSTED;
            $fresh->posted_by = $adminId;
            $fresh->posted_at = now();
            $fresh->save();

            $this->operationLog->record($adminId, 'inventory', 'check_post', 'inventory_check', $fresh->id, [
                'check_no' => $fresh->check_no,
                'adjusted' => $adjusted,
                'skipped' => $skipped,
                'unchanged' => $unchanged,
            ]);

            return [
                'adjusted' => $adjusted,
                'skipped' => $skipped,
                'unchanged' => $unchanged,
                'total_diff_qty' => $fresh->total_diff_qty,
            ];
        });
    }

    /** 作废：未过账的盘点单可作废，不产生任何库存变动 */
    public function cancel(InventoryCheck $check, int $adminId): void
    {
        if ($check->status === InventoryCheck::STATUS_POSTED) {
            throw BusinessException::conflict('该盘点单已过账，无法作废');
        }
        if ($check->status === InventoryCheck::STATUS_CANCELLED) {
            throw BusinessException::conflict('该盘点单已作废');
        }

        $check->status = InventoryCheck::STATUS_CANCELLED;
        $check->save();

        $this->operationLog->record($adminId, 'inventory', 'check_cancel', 'inventory_check', $check->id, [
            'check_no' => $check->check_no,
        ]);
    }

    /**
     * 明细导出行（含表头）
     *
     * @return array<int, array<int, mixed>>
     */
    public function exportRows(InventoryCheck $check): array
    {
        $rows = [['SKU编码', '商品', '规格', '开单账面', '实盘数量', '快照差异', '实际调整', '状态', '备注']];

        foreach ($check->items()->orderBy('id')->get() as $item) {
            $rows[] = [
                $item->sku_code,
                $item->product_title,
                $item->specs_text ?? '',
                $item->system_qty,
                $item->counted_qty ?? '',
                $item->counted_qty === null ? '' : $item->counted_qty - $item->system_qty,
                $item->diff_qty ?? '',
                InventoryCheckItem::STATUS_LABELS[$item->status] ?? $item->status,
                $item->remark ?? '',
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------- 内部

    private function assertOpen(InventoryCheck $check): void
    {
        if (! $check->isOpen()) {
            throw BusinessException::conflict($check->status === InventoryCheck::STATUS_POSTED
                ? '该盘点单已过账，无法再修改'
                : '该盘点单已作废，无法再修改');
        }
    }

    /**
     * @param  array<int, int>  $skuIds
     */
    private function createItems(InventoryCheck $check, array $skuIds): void
    {
        $skus = ProductSku::whereIn('id', $skuIds)->with('product')->get();
        $inventories = Inventory::whereIn('sku_id', $skuIds)->get()->keyBy('sku_id');

        foreach ($skus as $sku) {
            $inventory = $inventories->get($sku->id);
            $specs = is_array($sku->specs) ? $sku->specs : [];
            $signature = ProductAttributeService::signature($specs);

            InventoryCheckItem::create([
                'check_id' => $check->id,
                'sku_id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'product_title' => $sku->product?->title,
                'specs_text' => $signature === '__default__' ? null : $signature,
                'system_qty' => $inventory?->stock ?? 0,
                'locked_qty' => $inventory?->locked_stock ?? 0,
                'status' => InventoryCheckItem::STATUS_PENDING,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $skuCodes
     * @return array<int, int>
     */
    private function resolveSkuIds(string $scopeType, ?string $scopeValue, array $skuCodes): array
    {
        $query = ProductSku::query()->whereHas('product');

        switch ($scopeType) {
            case InventoryCheck::SCOPE_CATEGORY:
                $categoryId = (int) ($scopeValue ?? 0);
                if ($categoryId <= 0) {
                    throw BusinessException::badRequest('请选择盘点范围对应的分类');
                }
                $ids = Category::where('parent_id', $categoryId)->pluck('id')->prepend($categoryId)->all();
                $query->whereHas('product', fn ($q) => $q->whereIn('category_id', $ids));
                break;

            case InventoryCheck::SCOPE_BRAND:
                $brandId = (int) ($scopeValue ?? 0);
                if ($brandId <= 0) {
                    throw BusinessException::badRequest('请选择盘点范围对应的品牌');
                }
                $query->whereHas('product', fn ($q) => $q->where('brand_id', $brandId));
                break;

            case InventoryCheck::SCOPE_KEYWORD:
                $keyword = mb_strtolower(trim((string) $scopeValue));
                if ($keyword === '') {
                    throw BusinessException::badRequest('请输入盘点范围的关键词');
                }
                // ⚠️ PG 的 LIKE 大小写敏感（SQLite 不敏感）：跨库一致必须两侧包 lower()
                $query->where(function ($q) use ($keyword): void {
                    $q->whereRaw('lower(sku_code) LIKE ?', ['%'.$keyword.'%'])
                        ->orWhereHas('product', fn ($p) => $p->whereRaw('lower(title) LIKE ?', ['%'.$keyword.'%']));
                });
                break;

            case InventoryCheck::SCOPE_CUSTOM:
                if ($skuCodes === []) {
                    throw BusinessException::badRequest('自定义清单模式下请上传含 SKU 编码的文件');
                }
                $query->whereIn('sku_code', $skuCodes);
                break;

            case InventoryCheck::SCOPE_ALL:
                break;

            default:
                throw BusinessException::badRequest('盘点范围类型不合法');
        }

        return $query->pluck('id')->all();
    }

    /** 重算单头统计（展示口径：与开单快照比较；过账后按实际调整量比较） */
    private function refreshStats(InventoryCheck $check, bool $posted = false): void
    {
        $items = $check->items()->get();

        if ($posted) {
            $counted = $items->whereNotNull('diff_qty');
            $diff = $counted->where('diff_qty', '!=', 0);
        } else {
            $counted = $items->whereNotNull('counted_qty');
            $diff = $counted->filter(fn ($i) => $i->counted_qty !== $i->system_qty);
        }

        $check->counted_count = $counted->count();
        $check->diff_count = $diff->count();
        $check->total_diff_qty = $diff->sum(fn ($i) => abs(($posted ? $i->diff_qty : $i->counted_qty) - ($posted ? 0 : $i->system_qty)));

        if (! $posted && $check->status === InventoryCheck::STATUS_DRAFT && $check->counted_count > 0) {
            $check->status = InventoryCheck::STATUS_COUNTING;
        }

        $check->save();
    }

    /** 盘点单号：PC + 日期 + 当日序号（唯一索引兜底） */
    private function nextCheckNo(): string
    {
        $seq = InventoryCheck::whereDate('created_at', today())->count() + 1;

        do {
            $no = 'PC'.now()->format('Ymd').str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (InventoryCheck::where('check_no', $no)->exists());

        return $no;
    }
}
