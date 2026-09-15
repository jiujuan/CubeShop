<?php

namespace App\Services\Product;

use App\Exceptions\BusinessException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Common\ConfigService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * 商品属性与 SKU 矩阵服务（V1.1 E01 / T-009）
 *
 * 核心能力：
 * 1. generateSkuMatrix —— 勾选规格值 → 笛卡尔积组合（含维度签名）
 * 2. mergeSkuMatrix    —— 差异合并：保留已填价格/库存，只增删变化行
 *
 * 设计要点：
 * - SKU 身份用「维度签名」（属性名 + 值名的确定性排序拼接）而非 id，
 *   避免因勾选顺序变化而误判为删除 + 新增。
 * - 在途/历史订单引用过的 SKU 不物理删除，改为禁用。
 */
class ProductAttributeService
{
    public function __construct(
        private InventoryService $inventory,
        private ConfigService $config,
    ) {
    }

    /** SKU 组合数上限（防误选导致组合爆炸） */
    public function maxSkus(): int
    {
        return max(1, $this->config->getInt('product.max_skus', 200));
    }

    /**
     * 生成笛卡尔积组合
     *
     * @param  array<int, array{attribute_id:int, values:array<int,int>}>  $specsSelection
     * @return array<int, array{signature:string, specs:array<string,string>}>
     *
     * @throws BusinessException 属性不存在 / 值不属于该属性 / 组合数超限
     */
    public function generateSkuMatrix(array $specsSelection): array
    {
        $dimensions = [];

        foreach ($specsSelection as $dimension) {
            $attributeId = (int) ($dimension['attribute_id'] ?? 0);
            $valueIds = array_values(array_unique(array_map('intval', $dimension['values'] ?? [])));

            if ($attributeId <= 0) {
                throw BusinessException::badRequest('规格维度参数不合法');
            }
            if ($valueIds === []) {
                // 该维度未选值：整体不参与组合
                continue;
            }

            /** @var Attribute|null $attribute */
            $attribute = Attribute::find($attributeId);
            if (! $attribute) {
                throw BusinessException::badRequest('规格属性不存在：'.$attributeId);
            }

            $values = AttributeValue::where('attribute_id', $attributeId)
                ->whereIn('id', $valueIds)
                ->get()
                ->sortBy(fn (AttributeValue $v) => array_search($v->id, $valueIds, true))
                ->values();

            if ($values->count() !== count($valueIds)) {
                throw BusinessException::badRequest(sprintf('属性「%s」存在无效的属性值', $attribute->name));
            }

            $dimensions[] = [
                'attribute_id' => $attributeId,
                'name' => $attribute->name,
                'values' => $values->map(fn (AttributeValue $v) => ['id' => $v->id, 'value' => $v->value])->all(),
            ];
        }

        if ($dimensions === []) {
            throw BusinessException::badRequest('请至少选择一个规格属性及其值');
        }

        // 组合数保护
        $total = 1;
        foreach ($dimensions as $dimension) {
            $total *= count($dimension['values']);
        }
        if ($total > $this->maxSkus()) {
            throw BusinessException::badRequest(sprintf(
                '将生成 %d 个 SKU，超过单商品上限 %d，请减少规格值',
                $total,
                $this->maxSkus(),
            ));
        }

        // 笛卡尔积
        $combos = [[]];
        foreach ($dimensions as $dimension) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($dimension['values'] as $value) {
                    $next[] = $combo + [$dimension['name'] => $value['value']];
                }
            }
            $combos = $next;
        }

        return array_map(fn (array $specs) => [
            'signature' => self::signature($specs),
            'specs' => $specs,
        ], $combos);
    }

    /**
     * 维度签名（SKU 身份标识）
     *
     * 对属性名排序后拼接 `名:值|名:值`，保证与勾选顺序无关。
     *
     * @param  array<string, string>  $specs
     */
    public static function signature(array $specs): string
    {
        if ($specs === []) {
            return '__default__';
        }

        ksort($specs);

        return implode('|', array_map(
            fn ($k, $v) => $k.':'.$v,
            array_keys($specs),
            array_values($specs),
        ));
    }

    /**
     * 预览：在不落库的情况下返回将与现有 SKU 的差异
     *
     * @param  array<int, array{attribute_id:int, values:array<int,int>}>  $specsSelection
     * @return array{total:int, created:array, kept:array, removed:array}
     */
    public function preview(?Product $product, array $specsSelection): array
    {
        $matrix = $this->generateSkuMatrix($specsSelection);
        $existing = $this->existingSignatureMap($product);

        $created = [];
        $kept = [];
        foreach ($matrix as $item) {
            if (isset($existing[$item['signature']])) {
                $kept[] = $item;
            } else {
                $created[] = $item;
            }
        }

        $matrixSignatures = array_column($matrix, 'signature');
        $removed = [];
        foreach ($existing as $signature => $sku) {
            if (! in_array($signature, $matrixSignatures, true)) {
                $removed[] = [
                    'sku_id' => $sku['id'],
                    'specs' => $sku['specs'],
                    'signature' => $signature,
                    'has_orders' => $sku['has_orders'],
                    // 有订单引用 → 禁用保留；无引用 → 删除
                    'action' => $sku['has_orders'] ? 'disable' : 'delete',
                ];
            }
        }

        return [
            'total' => count($matrix),
            'created' => $created,
            'kept' => $kept,
            'removed' => $removed,
            'max_skus' => $this->maxSkus(),
        ];
    }

    /**
     * 差异合并（在事务中执行）
     *
     * @param  array<int, array{signature:string, specs:array<string,string>}>  $matrix
     * @param  array<int, array{signature:string, price?:string, stock?:int, sku_code?:string, status?:int}>  $skuInputs  按签名提供的价格/库存
     * @return array{created:int, kept:int, disabled:int, deleted:int}
     */
    public function mergeSkuMatrix(Product $product, array $matrix, array $skuInputs = []): array
    {
        $inputMap = [];
        foreach ($skuInputs as $input) {
            if (isset($input['signature'])) {
                $inputMap[$input['signature']] = $input;
            }
        }

        return DB::transaction(function () use ($product, $matrix, $inputMap) {
            $existing = $this->existingSignatureMap($product);
            $matrixSignatures = array_column($matrix, 'signature');

            $created = 0;
            $kept = 0;
            $seq = $product->skus()->withTrashed()->count();

            foreach ($matrix as $item) {
                $signature = $item['signature'];
                $input = $inputMap[$signature] ?? [];

                if (isset($existing[$signature])) {
                    // 已存在：保留原值，仅在有显式输入时覆盖
                    $sku = ProductSku::withTrashed()->find($existing[$signature]['id']);
                    if (! $sku) {
                        continue;
                    }

                    // 重新被选中的组合：恢复软删除状态
                    if ($sku->trashed()) {
                        $sku->restore();
                    }

                    $sku->specs = $item['specs'];
                    if (array_key_exists('price', $input)) {
                        $sku->price = bcadd((string) $input['price'], '0', 2);
                    }
                    if (! empty($input['sku_code'])) {
                        $sku->sku_code = $input['sku_code'];
                    }
                    // 恢复被禁用但重新选中的组合
                    $sku->status = array_key_exists('status', $input) ? (int) $input['status'] : 1;
                    $sku->save();

                    if (array_key_exists('stock', $input)) {
                        $this->setStock((int) $sku->id, (int) $input['stock']);
                    }

                    $kept++;

                    continue;
                }

                // 新增
                $seq++;
                $sku = ProductSku::create([
                    'product_id' => $product->id,
                    'sku_code' => $input['sku_code'] ?? sprintf('CS-%d-%d', $product->id, $seq),
                    'specs' => $item['specs'],
                    'price' => bcadd((string) ($input['price'] ?? $product->price), '0', 2),
                    'status' => (int) ($input['status'] ?? 1),
                ]);

                Inventory::create([
                    'sku_id' => $sku->id,
                    'stock' => (int) ($input['stock'] ?? 0),
                    'locked_stock' => 0,
                ]);

                $created++;
            }

            // 消失的签名
            $disabled = 0;
            $deleted = 0;
            foreach ($existing as $signature => $info) {
                if (in_array($signature, $matrixSignatures, true)) {
                    continue;
                }

                $sku = ProductSku::withTrashed()->find($info['id']);
                if (! $sku) {
                    continue;
                }

                $this->zeroStock($sku->id);

                if ($info['has_orders']) {
                    // 有订单引用：不物理删除，改为禁用
                    $sku->status = 0;
                    $sku->save();
                    $disabled++;

                    continue;
                }

                $sku->delete(); // 软删除（订单引用由快照保证，历史数据不受影响）
                $deleted++;
            }

            return ['created' => $created, 'kept' => $kept, 'disabled' => $disabled, 'deleted' => $deleted];
        });
    }

    /**
     * 现有 SKU 的签名映射
     *
     * @return array<string, array{id:int, specs:array<string,string>, has_orders:bool}>
     */
    public function existingSignatureMap(?Product $product): array
    {
        if (! $product) {
            return [];
        }

        $skus = ProductSku::withTrashed()->where('product_id', $product->id)->get();
        $skuIds = $skus->pluck('id')->all();

        // 被订单引用过的 SKU（含历史订单）→ 不物理删除
        $referenced = $skuIds === []
            ? []
            : OrderItem::whereIn('sku_id', $skuIds)->pluck('sku_id')->unique()->all();
        $referenced = array_flip($referenced);

        $map = [];
        foreach ($skus as $sku) {
            $specs = is_array($sku->specs) ? $sku->specs : [];
            $map[self::signature($specs)] = [
                'id' => (int) $sku->id,
                'specs' => $specs,
                'has_orders' => isset($referenced[$sku->id]),
            ];
        }

        return $map;
    }

    /** 设置为指定库存（通过 adjust 差值写库存流水） */
    public function setStock(int $skuId, int $stock): void
    {
        $stock = max(0, $stock);
        $current = $this->inventory->getStock($skuId);

        if ($current === $stock) {
            return;
        }

        if ($current > $stock) {
            $this->inventory->adjust($skuId, -(int) ($current - $stock), null, 'SKU 矩阵调整：库存清零/下调');
        } else {
            $this->inventory->adjust($skuId, (int) ($stock - $current), null, 'SKU 矩阵调整：库存上调');
        }
    }

    /** 库存归零（SKU 下线） */
    private function zeroStock(int $skuId): void
    {
        $current = $this->inventory->getStock($skuId);
        if ($current > 0) {
            $this->inventory->adjust($skuId, -$current, null, 'SKU 移除：库存清零');
        }
    }

    /**
     * 按维度批量设置（如「所有 M 码 +5 元」）
     *
     * @param  array{attribute?:string, value?:string, price_delta?:string, price?:string, stock?:int, status?:int}  $rule
     * @return int 受影响 SKU 数
     */
    public function batchSet(Product $product, array $rule): int
    {
        $query = ProductSku::where('product_id', $product->id);
        $skus = $query->get();

        $affected = 0;

        DB::transaction(function () use ($skus, $rule, &$affected) {
            foreach ($skus as $sku) {
                $specs = is_array($sku->specs) ? $sku->specs : [];

                // 维度匹配：指定属性名与值时只命中该维度取该值的 SKU
                if (! empty($rule['attribute'])) {
                    $target = $specs[$rule['attribute']] ?? null;
                    if ($target === null) {
                        continue;
                    }
                    if (! empty($rule['value']) && $target !== $rule['value']) {
                        continue;
                    }
                }

                if (isset($rule['price_delta'])) {
                    $sku->price = bcadd((string) $sku->price, (string) $rule['price_delta'], 2);
                    if (bccomp($sku->price, '0.01', 2) === -1) {
                        $sku->price = '0.01';
                    }
                }
                if (isset($rule['price'])) {
                    $sku->price = bcadd((string) $rule['price'], '0', 2);
                }
                if (array_key_exists('status', $rule)) {
                    $sku->status = (int) $rule['status'];
                }
                $sku->save();

                if (array_key_exists('stock', $rule)) {
                    $this->setStock((int) $sku->id, (int) $rule['stock']);
                }

                $affected++;
            }
        });

        return $affected;
    }
}
