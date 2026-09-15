<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductSku;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * 存量商品 specs 数据迁移（V1.1 E01 / T-012）
 *
 * 目标：把存量商品 `product_skus.specs`（JSONB）反推注册为属性库数据，
 * 并输出人工可核对的清单；**不修改任何商品数据**。
 *
 * 用法：
 *   php artisan products:migrate-specs               # 默认 dry-run，只出清单
 *   php artisan products:migrate-specs --apply       # 写入属性库
 *   php artisan products:migrate-specs --limit=500   # 只扫描前 N 个 SKU
 */
class MigrateProductSpecs extends Command
{
    protected $signature = 'products:migrate-specs
                            {--apply : 实际写入属性库（默认只输出清单）}
                            {--limit=0 : 只扫描前 N 个 SKU（0 表示全部）}
                            {--output= : 清单输出路径（默认 docs/testing/evidence/v1.1/T-012/specs-inventory.md）}';

    protected $description = '扫描存量 SKU 规格，输出校对清单并按需写入属性库（幂等、不改动商品数据）';

    /** 疑似脏数据的判定规则 */
    private const MAX_VALUE_LENGTH = 32;

    private const MAX_DIMENSION_COUNT = 6;

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        $dimensions = [];   // 维度名 => ['values' => [值 => 次数], 'products' => [商品ID => true]]
        $skuScanned = 0;
        $skuWithSpecs = 0;

        $query = ProductSku::query()->withTrashed()->select(['id', 'product_id', 'specs'])->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $sku) {
            $skuScanned++;
            $specs = is_array($sku->specs) ? $sku->specs : [];
            if ($specs === []) {
                continue;
            }
            $skuWithSpecs++;

            foreach ($specs as $name => $value) {
                $name = trim((string) $name);
                $value = trim((string) $value);
                if ($name === '') {
                    continue;
                }

                $dimensions[$name] ??= ['values' => [], 'products' => []];
                $dimensions[$name]['values'][$value] = ($dimensions[$name]['values'][$value] ?? 0) + 1;
                $dimensions[$name]['products'][$sku->product_id] = true;
            }
        }

        if ($dimensions === []) {
            $this->info("扫描 {$skuScanned} 个 SKU，未发现任何规格数据（无需迁移）");

            return self::SUCCESS;
        }

        // 脏数据识别
        $issues = [];
        foreach ($dimensions as $name => $info) {
            foreach ($info['values'] as $value => $count) {
                if ($value === '') {
                    $issues[] = [$name, $value, $count, '空值'];
                } elseif (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                    $issues[] = [$name, $value, $count, '超长（>'.self::MAX_VALUE_LENGTH.' 字）'];
                } elseif (preg_match('/[<>{}[\]\\\\\/|]/u', $value)) {
                    $issues[] = [$name, $value, $count, '含特殊字符'];
                } elseif (preg_match('/^(CS|SKU)[-_]?\d+/i', $value)) {
                    $issues[] = [$name, $value, $count, '疑似 SKU 编码被误填为规格值'];
                }
            }

            // 同维度大小写不一致
            $lower = [];
            foreach (array_keys($info['values']) as $value) {
                $lower[mb_strtolower($value)][] = $value;
            }
            foreach ($lower as $variants) {
                if (count($variants) > 1) {
                    $issues[] = [$name, implode(' / ', $variants), 0, '同一维度值大小写/写法不一致'];
                }
            }
        }

        $reportPath = $this->option('output') ?: base_path('../docs/testing/evidence/v1.1/T-012/specs-inventory.md');
        $this->writeInventory($reportPath, $dimensions, $issues, $skuScanned, $skuWithSpecs);

        $this->info(sprintf(
            '扫描完成：SKU %d 个（含规格 %d 个），维度 %d 个，候选值 %d 个，疑似脏数据 %d 条',
            $skuScanned,
            $skuWithSpecs,
            count($dimensions),
            array_sum(array_map(fn ($d) => count($d['values']), $dimensions)),
            count($issues),
        ));
        $this->line('校对清单：'.$reportPath);

        if (! $apply) {
            $this->warn('当前为 dry-run（未写入）。确认清单后加 --apply 执行写入。');

            return self::SUCCESS;
        }

        $result = $this->applyToAttributeLibrary($dimensions);
        $this->info(sprintf(
            '写入完成：新建属性 %d 个，新建属性值 %d 个，新建分类模板关联 %d 条',
            $result['attributes'],
            $result['values'],
            $result['templates'],
        ));
        $this->warn('注意：本命令不修改任何商品数据；清单中标记的脏数据需人工在后台修正。');

        return self::SUCCESS;
    }

    /**
     * 写入属性库（幂等）
     *
     * @param  array<string, array{values:array<string,int>, products:array<int,bool>}>  $dimensions
     * @return array{attributes:int, values:int, templates:int}
     */
    private function applyToAttributeLibrary(array $dimensions): array
    {
        $createdAttributes = 0;
        $createdValues = 0;
        $createdTemplates = 0;

        return DB::transaction(function () use ($dimensions, &$createdAttributes, &$createdValues, &$createdTemplates) {
            foreach ($dimensions as $name => $info) {
                $attribute = Attribute::where('name', $name)->first();
                if (! $attribute) {
                    $attribute = Attribute::create([
                        'name' => $name,
                        'type' => Attribute::TYPE_SPEC,
                        'is_filterable' => false, // 迁移期默认不可筛，人工确认后再开启
                        'is_multiple' => false,
                        'allow_custom' => false,
                        'sort' => 0,
                    ]);
                    $createdAttributes++;
                }

                foreach (array_keys($info['values']) as $value) {
                    if ($value === '') {
                        continue;
                    }
                    $exists = AttributeValue::where('attribute_id', $attribute->id)->where('value', $value)->exists();
                    if ($exists) {
                        continue;
                    }
                    AttributeValue::create(['attribute_id' => $attribute->id, 'value' => $value, 'sort' => 0]);
                    $createdValues++;
                }

                // 按涉及商品的分类建立模板关联
                $categoryIds = Product::whereIn('id', array_keys($info['products']))
                    ->whereNotNull('category_id')
                    ->distinct()
                    ->pluck('category_id')
                    ->all();

                if ($categoryIds === []) {
                    // 无法归属分类：写入「通用」处理——此处不建模板，仅在清单中标注待人工归类
                    continue;
                }

                foreach ($categoryIds as $categoryId) {
                    $exists = CategoryAttribute::where('category_id', $categoryId)
                        ->where('attribute_id', $attribute->id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    CategoryAttribute::create([
                        'category_id' => $categoryId,
                        'attribute_id' => $attribute->id,
                        'is_required' => false,
                        'sort' => 0,
                    ]);
                    $createdTemplates++;
                }
            }

            return ['attributes' => $createdAttributes, 'values' => $createdValues, 'templates' => $createdTemplates];
        });
    }

    /**
     * 生成校对清单
     *
     * @param  array<string, array{values:array<string,int>, products:array<int,bool>}>  $dimensions
     * @param  array<int, array{0:string,1:string,2:int,3:string}>  $issues
     */
    private function writeInventory(string $path, array $dimensions, array $issues, int $skuScanned, int $skuWithSpecs): void
    {
        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $lines = [];
        $lines[] = '# 存量规格数据校对清单（V1.1 T-012）';
        $lines[] = '';
        $lines[] = '> 由 `php artisan products:migrate-specs` 自动生成，请人工核对后再执行 `--apply`。';
        $lines[] = '> **本清单不会修改任何商品数据**。';
        $lines[] = '';
        $lines[] = sprintf('- 扫描 SKU：%d 个（其中含规格 %d 个）', $skuScanned, $skuWithSpecs);
        $lines[] = sprintf('- 维度：%d 个', count($dimensions));
        $lines[] = sprintf('- 候选值：%d 个', array_sum(array_map(fn ($d) => count($d['values']), $dimensions)));
        $lines[] = sprintf('- 疑似脏数据：%d 条', count($issues));
        $lines[] = '';
        $lines[] = '## 1. 维度与候选值';
        $lines[] = '';
        $lines[] = '| 维度 | 候选值数 | 涉及商品数 | 候选值（出现次数） |';
        $lines[] = '|------|----------|------------|--------------------|';

        foreach ($dimensions as $name => $info) {
            arsort($info['values']);
            $pairs = [];
            foreach (array_slice($info['values'], 0, 20, true) as $value => $count) {
                $pairs[] = sprintf('%s(%d)', $value === '' ? '(空)' : $value, $count);
            }
            $lines[] = sprintf(
                '| %s | %d | %d | %s%s |',
                $name,
                count($info['values']),
                count($info['products']),
                implode('、', $pairs),
                count($info['values']) > 20 ? ' …' : '',
            );
        }

        $lines[] = '';
        $lines[] = '## 2. 疑似脏数据（需人工确认）';
        $lines[] = '';
        if ($issues === []) {
            $lines[] = '无。';
        } else {
            $lines[] = '| 维度 | 值 | 出现次数 | 问题 |';
            $lines[] = '|------|----|----------|------|';
            foreach ($issues as [$name, $value, $count, $reason]) {
                $lines[] = sprintf('| %s | %s | %d | %s |', $name, $value === '' ? '(空)' : $value, $count, $reason);
            }
        }

        $lines[] = '';
        $lines[] = '## 3. 无法归属分类的维度（待人工归类）';
        $lines[] = '';
        $generic = [];
        foreach ($dimensions as $name => $info) {
            $hasCategory = Product::whereIn('id', array_keys($info['products']))
                ->whereNotNull('category_id')
                ->exists();
            if (! $hasCategory) {
                $generic[] = $name;
            }
        }
        $lines[] = $generic === [] ? '无。' : '- '.implode("\n- ", $generic);
        $lines[] = '';

        File::put($path, implode("\n", $lines));
    }
}
