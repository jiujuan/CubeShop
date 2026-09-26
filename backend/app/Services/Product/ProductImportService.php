<?php

namespace App\Services\Product;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductSku;
use App\Services\Inventory\InventoryService;
use App\Support\ImportRules;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Support\Facades\DB;

/**
 * 商品批量导入（xlsx）
 *
 * 与批量发货（BatchShipService）同一套心智：
 *   解析 → 逐行预校验 → **全部通过才执行**（单事务）→ 返回逐行失败原因。
 * 部分成功一律不支持，这样「一次导入要么全成要么全不成」，运营不需要对账半成品。
 *
 * 两种模式：
 * - create：新建商品 + SKU + 库存。同一「商品编码」的行合并为一个商品（编码为空时按标题合并）。
 * - update：按 SKU 编码定位，只改 销售价 / 库存 / SKU状态，不动商品主信息。
 *
 * 边界（V1 明确不做）：外链图片下载、商品图集、分类属性模板、运费模板、异步队列。
 */
class ProductImportService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * 原始二维表（含表头行）→ 记录数组
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function parse(array $rows): array
    {
        $records = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $cell = fn (int $idx): string => trim((string) ($row[$idx] ?? ''));

            $records[] = [
                // +2：Excel 行号（1 行表头 + 0 基索引），失败明细里直接给人看
                'row' => $i + 2,
                'code' => $cell(0),
                'title' => $cell(1),
                'subtitle' => $cell(2),
                'category' => $cell(3),
                'brand' => $cell(4),
                'main_image' => $cell(5),
                'description_md' => (string) ($row[6] ?? ''),
                'status' => $cell(7),
                'weight' => $cell(8),
                'sort' => $cell(9),
                'sku_code' => $cell(10),
                'specs' => $cell(11),
                'price' => $cell(12),
                'stock' => $cell(13),
                'sku_status' => $cell(14),
            ];
        }

        return $records;
    }

    /**
     * 预校验：任一行为失败则整批不执行
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array{valid: array<string, mixed>, failed: array<int, array<string, mixed>>}
     */
    public function validate(string $mode, array $records): array
    {
        return $mode === ImportRules::MODE_UPDATE
            ? $this->validateUpdate($records)
            : $this->validateCreate($records);
    }

    /**
     * 执行导入（调用方需已确认 failed 为空）
     *
     * @param  array<string, mixed>  $valid  validate() 返回的 valid 结构
     * @return array{rows: int, products: int}
     */
    public function execute(string $mode, array $valid, int $adminId): array
    {
        return DB::transaction(
            fn (): array => $mode === ImportRules::MODE_UPDATE
                ? $this->executeUpdate($valid, $adminId)
                : $this->executeCreate($valid)
        );
    }

    // ---------------------------------------------------------------- create

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{valid: array<string, mixed>, failed: array<int, array<string, mixed>>}
     */
    private function validateCreate(array $records): array
    {
        $failed = [];

        // 预加载：逐行查库会退化成 N+1，300 行放大后很明显
        $categories = $this->loadCategories($records);
        $brands = $this->loadBrands($records);
        $usedCodes = $this->loadExistingProductCodes($records);
        $usedSkuCodes = $this->loadExistingSkuCodes($records);

        // 按「商品编码 ?: 商品标题」聚合——同一商品的多行（多规格）合成一个商品
        $groups = [];
        foreach ($records as $rec) {
            $key = $rec['code'] !== '' ? 'c:'.$rec['code'] : 't:'.$rec['title'];
            $groups[$key][] = $rec;
        }

        $validGroups = [];
        $seenSkuCode = [];

        foreach ($groups as $key => $rows) {
            $first = $rows[0];
            $groupFailed = false;

            // —— 商品级校验（以组内第一行为准）——
            if ($first['title'] === '') {
                $failed[] = $this->fail($first, '商品标题不能为空');
                $groupFailed = true;
            }

            $categoryId = null;
            if (! $groupFailed) {
                if ($first['category'] === '') {
                    $failed[] = $this->fail($first, '分类不能为空');
                    $groupFailed = true;
                } else {
                    $categoryId = $this->resolveCategoryId($first['category'], $categories);
                    if ($categoryId === -1) {
                        $failed[] = $this->fail($first, '分类「'.$first['category'].'」存在多个同名分类，请改填分类ID');
                        $groupFailed = true;
                    } elseif ($categoryId === null) {
                        $failed[] = $this->fail($first, '分类「'.$first['category'].'」不存在');
                        $groupFailed = true;
                    }
                }
            }

            $brandId = null;
            if (! $groupFailed && $first['brand'] !== '') {
                if (! isset($brands[$first['brand']])) {
                    $failed[] = $this->fail($first, '品牌「'.$first['brand'].'」不存在');
                    $groupFailed = true;
                } else {
                    $brandId = $brands[$first['brand']];
                }
            }

            $status = $this->parseFlag($first['status'], 0);
            if ($status === null) {
                $failed[] = $this->fail($first, '商品状态只能是「上架」或「下架」');
                $groupFailed = true;
            }

            $weight = $this->parseInt($first['weight'], 0);
            if ($weight === null) {
                $failed[] = $this->fail($first, '重量必须是 ≥0 的整数（克）');
                $groupFailed = true;
            }

            $sort = $this->parseInt($first['sort'], 0);
            if ($sort === null) {
                $failed[] = $this->fail($first, '排序必须是整数');
                $groupFailed = true;
            }

            if (! $groupFailed && $first['code'] !== '' && isset($usedCodes[$first['code']])) {
                $failed[] = $this->fail($first, '商品编码「'.$first['code'].'」已存在，如需更新请改用「更新」模式');
                $groupFailed = true;
            }

            if (! $groupFailed && $this->isExternalUrl($first['main_image'])) {
                $failed[] = $this->fail($first, '主图请填写已上传的相对路径（暂不支持自动下载外链图片）');
                $groupFailed = true;
            }

            // 组内商品级字段必须一致（多行本应描述同一个商品）
            if (! $groupFailed) {
                foreach ($rows as $rec) {
                    if ($rec['title'] !== $first['title'] || $rec['code'] !== $first['code']) {
                        $failed[] = $this->fail($rec, '同一商品编码下「商品编码/商品标题」必须一致');
                        $groupFailed = true;
                        break;
                    }
                }
            }

            if ($groupFailed) {
                continue;
            }

            // —— SKU 级校验 ——
            $skuRows = [];
            $signatures = [];
            $skuFailed = false;

            foreach ($rows as $rec) {
                $price = $this->parsePrice($rec['price']);
                if ($price === null) {
                    $failed[] = $this->fail($rec, '销售价必须是 ≥0.01 的数字');
                    $skuFailed = true;
                    continue;
                }

                $stock = $this->parseInt($rec['stock'], 0);
                if ($stock === null) {
                    $failed[] = $this->fail($rec, '库存必须是 ≥0 的整数');
                    $skuFailed = true;
                    continue;
                }

                $skuStatus = $this->parseFlag($rec['sku_status'], 1);
                if ($skuStatus === null) {
                    $failed[] = $this->fail($rec, 'SKU状态只能是「启用」或「停用」');
                    $skuFailed = true;
                    continue;
                }

                $specs = $this->parseSpecs($rec['specs']);
                $signature = ProductAttributeService::signature($specs);

                if (isset($signatures[$signature])) {
                    $failed[] = $this->fail($rec, '规格与同商品第 '.$signatures[$signature].' 行重复');
                    $skuFailed = true;
                    continue;
                }
                $signatures[$signature] = $rec['row'];

                if ($rec['sku_code'] !== '') {
                    if (isset($seenSkuCode[$rec['sku_code']])) {
                        $failed[] = $this->fail($rec, 'SKU编码「'.$rec['sku_code'].'」在本次文件中重复');
                        $skuFailed = true;
                        continue;
                    }
                    if (isset($usedSkuCodes[$rec['sku_code']])) {
                        $failed[] = $this->fail($rec, 'SKU编码「'.$rec['sku_code'].'」已被占用（含历史删除记录），请更换');
                        $skuFailed = true;
                        continue;
                    }
                    $seenSkuCode[$rec['sku_code']] = $rec['row'];
                }

                $skuRows[] = [
                    'row' => $rec['row'],
                    'sku_code' => $rec['sku_code'],
                    'specs' => $specs,
                    'price' => $price,
                    'stock' => $stock,
                    'status' => $skuStatus,
                ];
            }

            if ($skuFailed) {
                continue;
            }

            $validGroups[] = [
                'key' => $key,
                'product' => [
                    'code' => $first['code'] !== '' ? $first['code'] : null,
                    'title' => $first['title'],
                    'subtitle' => $first['subtitle'] !== '' ? $first['subtitle'] : null,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'main_image' => $first['main_image'] !== '' ? $first['main_image'] : null,
                    'description_md' => $first['description_md'] !== '' ? $first['description_md'] : null,
                    'status' => $status,
                    'weight' => $weight,
                    'sort' => $sort,
                ],
                'skus' => $skuRows,
            ];
        }

        return ['valid' => ['groups' => $validGroups], 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array{rows: int, products: int}
     */
    private function executeCreate(array $valid): array
    {
        $rows = 0;
        $products = 0;

        foreach ($valid['groups'] ?? [] as $group) {
            // products.price 为 NOT NULL：展示价必须在建行时就带上，不能建完再补
            $product = Product::create([
                ...$group['product'],
                'price' => $this->displayPrice($group['skus']),
            ]);

            $seq = 0;
            foreach ($group['skus'] as $sku) {
                $seq++;
                $code = $sku['sku_code'] !== '' ? $sku['sku_code'] : $this->nextSkuCode($product->id, $seq);

                $skuModel = ProductSku::create([
                    'product_id' => $product->id,
                    'sku_code' => $code,
                    'specs' => $sku['specs'],
                    'price' => $sku['price'],
                    'status' => $sku['status'],
                ]);

                Inventory::create([
                    'sku_id' => $skuModel->id,
                    'stock' => $sku['stock'],
                ]);

                $rows++;
            }

            // 检索索引含 SKU 编码与标题，导入的商品必须能被前台搜到
            app(SearchIndexWriter::class)->reindex($product);

            $products++;
        }

        return ['rows' => $rows, 'products' => $products];
    }

    // ---------------------------------------------------------------- update

    /**
     * update 模式只认「SKU编码 / 销售价 / 库存 / SKU状态」四列
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array{valid: array<string, mixed>, failed: array<int, array<string, mixed>>}
     */
    private function validateUpdate(array $records): array
    {
        $failed = [];
        $items = [];
        $seen = [];

        $codes = array_values(array_unique(array_filter(array_map(fn ($r) => $r['sku_code'], $records))));
        $existing = $codes === []
            ? collect()
            : ProductSku::whereIn('sku_code', $codes)->get()->keyBy('sku_code');

        foreach ($records as $rec) {
            if ($rec['sku_code'] === '') {
                $failed[] = $this->fail($rec, '更新模式必须填写 SKU编码');
                continue;
            }

            if (! $existing->has($rec['sku_code'])) {
                $failed[] = $this->fail($rec, 'SKU编码「'.$rec['sku_code'].'」不存在');
                continue;
            }

            if (isset($seen[$rec['sku_code']])) {
                $failed[] = $this->fail($rec, 'SKU编码「'.$rec['sku_code'].'」在本次文件中重复');
                continue;
            }
            $seen[$rec['sku_code']] = $rec['row'];

            $price = $rec['price'] === '' ? null : $this->parsePrice($rec['price']);
            if ($rec['price'] !== '' && $price === null) {
                $failed[] = $this->fail($rec, '销售价必须是 ≥0.01 的数字');
                continue;
            }

            $stock = $rec['stock'] === '' ? null : $this->parseInt($rec['stock'], 0);
            if ($rec['stock'] !== '' && $stock === null) {
                $failed[] = $this->fail($rec, '库存必须是 ≥0 的整数');
                continue;
            }

            $status = $rec['sku_status'] === '' ? null : $this->parseFlag($rec['sku_status'], 1);
            if ($rec['sku_status'] !== '' && $status === null) {
                $failed[] = $this->fail($rec, 'SKU状态只能是「启用」或「停用」');
                continue;
            }

            if ($price === null && $stock === null && $status === null) {
                $failed[] = $this->fail($rec, '销售价 / 库存 / SKU状态 至少填写一项');
                continue;
            }

            $items[] = [
                'row' => $rec['row'],
                'sku_code' => $rec['sku_code'],
                'sku_id' => (int) $existing->get($rec['sku_code'])->id,
                'price' => $price,
                'stock' => $stock,
                'status' => $status,
            ];
        }

        return ['valid' => ['items' => $items], 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array{rows: int, products: int}
     */
    private function executeUpdate(array $valid, int $adminId): array
    {
        $rows = 0;

        foreach ($valid['items'] ?? [] as $item) {
            $sku = ProductSku::find($item['sku_id']);
            if (! $sku) {
                continue;
            }

            $dirty = false;
            if ($item['price'] !== null) {
                $sku->price = $item['price'];
                $dirty = true;
            }
            if ($item['status'] !== null) {
                $sku->status = $item['status'];
                $dirty = true;
            }
            if ($dirty) {
                $sku->save();
            }

            if ($item['stock'] !== null) {
                $current = $this->inventory->getStock($sku->id);
                if ($current !== $item['stock']) {
                    // 走 adjust 而非裸 update：库存流水是唯一的对账依据，不能断
                    $this->inventory->adjust(
                        $sku->id,
                        $item['stock'] - $current,
                        $adminId,
                        sprintf('批量导入更新库存（文件第 %d 行）', $item['row']),
                        'import',
                    );
                }
            }

            $rows++;
        }

        return ['rows' => $rows, 'products' => 0];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * 分类解析：数字按 ID，其它按名称
     *
     * @param  array<string, array<int, int>>  $categories  名称 → [分类ID...]
     * @return int|null  null = 不存在；-1 = 名称不唯一
     */
    private function resolveCategoryId(string $value, array $categories): ?int
    {
        if (ctype_digit($value)) {
            return isset($categories['#'.$value]) ? (int) $value : null;
        }

        $ids = $categories[$value] ?? [];

        return match (count($ids)) {
            1 => $ids[0],
            0 => null,
            default => -1,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, array<int, int>>
     */
    private function loadCategories(array $records): array
    {
        $ids = [];
        $names = [];

        foreach ($records as $rec) {
            if ($rec['category'] === '') {
                continue;
            }
            if (ctype_digit($rec['category'])) {
                $ids[] = (int) $rec['category'];
            } else {
                $names[] = $rec['category'];
            }
        }

        $map = [];
        foreach (Category::whereIn('id', array_unique($ids))->pluck('id')->all() as $id) {
            $map['#'.$id] = [(int) $id];
        }
        foreach (Category::whereIn('name', array_unique($names))->get(['id', 'name']) as $cat) {
            $map[$cat->name][] = (int) $cat->id;
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, int>
     */
    private function loadBrands(array $records): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($r) => $r['brand'], $records))));

        return $names === []
            ? []
            : Brand::whereIn('name', $names)->pluck('id', 'name')
                ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * 商品编码占用表（含软删行：软删商品仍占唯一索引）
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, true>
     */
    private function loadExistingProductCodes(array $records): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($r) => $r['code'], $records))));

        return $codes === []
            ? []
            : Product::withTrashed()->whereIn('code', $codes)->pluck('code')
                ->mapWithKeys(fn ($c) => [(string) $c => true])->all();
    }

    /**
     * SKU 编码占用表（含软删行，与 ProductController::assertSkuCodeAvailable 同体例）
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, true>
     */
    private function loadExistingSkuCodes(array $records): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($r) => $r['sku_code'], $records))));

        return $codes === []
            ? []
            : ProductSku::withTrashed()->whereIn('sku_code', $codes)->pluck('sku_code')
                ->mapWithKeys(fn ($c) => [(string) $c => true])->all();
    }

    /** `颜色:红色|尺码:M` → ['颜色' => '红色', '尺码' => 'M'] */
    private function parseSpecs(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $specs = [];
        foreach (explode('|', $text) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode(':', $pair, 2), 2, '');
            $specs[trim($name)] = trim($value);
        }

        return $specs;
    }

    /** 上架/下架、启用/停用、1/0 → int；非法返回 null */
    private function parseFlag(string $text, int $default): ?int
    {
        if ($text === '') {
            return $default;
        }

        return match ($text) {
            '1', '上架', '启用', '是', 'on' => 1,
            '0', '下架', '停用', '否', 'off' => 0,
            default => null,
        };
    }

    /** ≥0 整数；空返回 default；非法返回 null */
    private function parseInt(string $text, int $default): ?int
    {
        if ($text === '') {
            return $default;
        }
        if (! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }

    /** 价格字符串 → 保留两位的小数字符串；非法或 <0.01 返回 null */
    private function parsePrice(string $text): ?string
    {
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        $value = number_format((float) $text, 2, '.', '');

        return bccomp($value, '0.01', 2) >= 0 ? $value : null;
    }

    private function isExternalUrl(string $path): bool
    {
        return $path !== '' && (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'));
    }

    /** 商品内自增且全表未占用的 SKU 编码（与 ProductController::generateSkuCode 同规则） */
    private function nextSkuCode(int $productId, int $seq): string
    {
        do {
            $code = sprintf('CS-%d-%d', $productId, $seq);
            $seq++;
        } while (ProductSku::withTrashed()->where('sku_code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<int, array<string, mixed>>  $skus
     */
    private function displayPrice(array $skus): string
    {
        $enabled = array_values(array_filter($skus, fn ($s) => $s['status'] === 1));
        $pool = $enabled !== [] ? $enabled : $skus;

        return collect($pool)->pluck('price')->min() ?? '0.00';
    }

    /**
     * @param  array<string, mixed>  $rec
     * @return array<string, mixed>
     */
    private function fail(array $rec, string $reason): array
    {
        return [
            'row' => $rec['row'],
            'code' => $rec['code'],
            'sku_code' => $rec['sku_code'],
            'reason' => $reason,
        ];
    }
}
