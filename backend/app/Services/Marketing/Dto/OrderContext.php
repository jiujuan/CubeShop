<?php

namespace App\Services\Marketing\Dto;

/**
 * 下单/结算金额上下文（V1.1 F06 / T-034）
 *
 * **纯值对象**：只承载「商品行 + 运费」，不依赖数据库，便于单元测试。
 * 行项目结构：
 *   ```
 *   [
 *     'product_id'  => 1|null,
 *     'sku_id'      => 11|null,
 *     'category_id' => 2|null,   // 券/满减按分类命中时需要，缺失可由 CouponService::buildContext 回库补全
 *     'price'       => '19.90',  // 下单快照单价
 *     'quantity'    => 2,
 *   ]
 *   ```
 * 归一化后每行追加 `amount = round(price * quantity, 2)` 与 `index`（从 0 起，连续）。
 *
 * 命中金额（scope base）口径：**行项目原始金额之和**（不因其它优惠而缩减），
 * 用于判定优惠券门槛与满减梯度是否够格。
 */
final class OrderContext
{
    /**
     * @var array<int, array{index:int, product_id:?int, sku_id:?int, category_id:?int, price:float, quantity:int, amount:float}>
     */
    public readonly array $lines;

    /** 商品总额（Σ 行金额），保留 2 位小数 */
    public readonly float $goodsAmount;

    /** 运费，保留 2 位小数 */
    public readonly float $freightAmount;

    /**
     * @param  array<int, array<string, mixed>>  $items  行项目（见类注释）
     * @param  float|string  $freightAmount  运费
     */
    public function __construct(array $items, float|string $freightAmount = 0.0)
    {
        $lines = [];
        $goods = 0.0;

        foreach (array_values($items) as $i => $item) {
            $price = round((float) ($item['price'] ?? 0), 2);
            $qty = max(0, (int) ($item['quantity'] ?? 0));
            $amount = round($price * $qty, 2);
            $goods = round($goods + $amount, 2);

            $lines[] = [
                'index' => $i,
                'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                'sku_id' => isset($item['sku_id']) ? (int) $item['sku_id'] : null,
                'category_id' => isset($item['category_id']) ? (int) $item['category_id'] : null,
                'price' => $price,
                'quantity' => $qty,
                'amount' => $amount,
            ];
        }

        $this->lines = $lines;
        $this->goodsAmount = round($goods, 2);
        $this->freightAmount = round((float) $freightAmount, 2);
    }

    /** 商品总件数 */
    public function totalQuantity(): int
    {
        $sum = 0;
        foreach ($this->lines as $l) {
            $sum += $l['quantity'];
        }

        return $sum;
    }

    /**
     * 命中指定适用范围的行索引
     *
     * @param  string  $scope  all|category|product
     * @param  array<int, int|string>  $refs  category/product id 列表（scope=all 时忽略）
     * @return array<int, int>
     */
    public function scopedIndexes(string $scope, array $refs = []): array
    {
        $refs = array_map('intval', $refs);

        $out = [];
        foreach ($this->lines as $l) {
            $hit = match ($scope) {
                'all' => true,
                'product' => $l['product_id'] !== null && in_array($l['product_id'], $refs, true),
                'category' => $l['category_id'] !== null && in_array($l['category_id'], $refs, true),
                default => false,
            };

            if ($hit) {
                $out[] = $l['index'];
            }
        }

        return $out;
    }

    /**
     * 命中适用范围的原始金额之和（用于门槛判定与梯度匹配）
     *
     * @param  array<int, int|string>  $refs
     */
    public function scopeBaseAmount(string $scope, array $refs = []): float
    {
        $sum = 0.0;
        foreach ($this->scopedIndexes($scope, $refs) as $i) {
            $sum += $this->lines[$i]['amount'];
        }

        return round($sum, 2);
    }
}
