<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CategoryAttribute;
use App\Models\Inventory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\ProductSku;
use App\Services\Common\OperationLogService;
use App\Services\Product\ProductAttributeService;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 商品管理（API 文档 8.1）
 * 权限：product.view / product.create / product.update
 */
class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OperationLogService $opLog,
        private ProductAttributeService $attributes,
    ) {
    }

    /** 商品列表（分页 + keyword/category_id/status 筛选） */
    public function index(Request $request): JsonResponse
    {
        $q = Product::query()
            ->with('category:id,name')
            ->withCount(['skus as in_stock_count' => function ($query) {
                $query->whereHas('inventory', fn ($i) => $i->where('stock', '>', 0));
            }])
            // 列表附带总库存与销量
            ->addSelect([
                'products.*',
                'total_stock' => ProductSku::query()
                    ->selectRaw('coalesce(sum(i.stock),0)')
                    ->join('inventories as i', 'i.sku_id', '=', 'product_skus.id')
                    ->whereColumn('product_skus.product_id', 'products.id')
                    ->limit(1),
            ]);

        if ($keyword = trim((string) $request->query('keyword'))) {
            $q->where('title', 'like', "%{$keyword}%");
        }
        if ($categoryId = (int) $request->query('category_id')) {
            // 含子分类
            $categoryIds = [$categoryId];
            foreach (Category::where('parent_id', $categoryId)->pluck('id') as $childId) {
                $categoryIds[] = $childId;
            }
            $q->whereIn('category_id', $categoryIds);
        }
        if (($status = $request->query('status')) !== null && $status !== '') {
            $q->where('status', (int) $status);
        }
        if ($minPrice = $request->query('min_price')) {
            $q->where('price', '>=', (float) $minPrice);
        }
        if ($maxPrice = $request->query('max_price')) {
            $q->where('price', '<=', (float) $maxPrice);
        }

        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'price_asc' => $q->orderBy('price'),
            'price_desc' => $q->orderByDesc('price'),
            'sales_desc' => $q->orderByDesc('sales_count'),
            default => $q->orderByDesc('id'),
        };

        return $this->paginated(
            $q->paginate((int) $request->query('page_size', 10))
        );
    }

    /** 商品详情（管理端编辑用） */
    public function show(int $id): JsonResponse
    {
        $product = Product::with([
            'skus.inventory', 'images', 'category:id,name',
            'brand:id,name', 'attributeValues.attribute:id,name',
        ])->find($id);
        if (! $product) {
            return $this->fail('商品不存在', 40004);
        }

        return $this->success($this->formatProduct($product));
    }

    /** 创建商品（含 SKU、多图、属性参数；V1.1 支持 specs_selection 笛卡尔积生成） */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $this->validateAttributeValues($payload);

        try {
            $product = DB::transaction(function () use ($payload) {
                $product = Product::create([
                    ...collect($payload)->except(['skus', 'images', 'specs_selection', 'attribute_values'])->all(),
                    'price' => $this->resolveProductPrice($payload),
                ]);

                $this->persistSkus($product, $payload);
                $this->saveImages($product, $payload['images'] ?? []);
                $this->saveAttributeValues($product, $payload['attribute_values'] ?? []);

                // SKU 建立后按最低有效价回写展示价
                $product->price = $this->refreshDisplayPrice($product);
                $product->save();

                return $product;
            });
        } catch (QueryException $e) {
            if ($this->isSkuCodeUniqueViolation($e)) {
                throw BusinessException::conflict('SKU 编码已存在，请更换后重试');
            }

            throw $e;
        }

        $this->opLog->record(request()->user()?->id, 'product', 'create', 'Product', $product->id);

        return $this->success(['id' => $product->id], '创建成功');
    }

    /** 更新商品（V1.1：SKU 矩阵差异合并，保留已填价格库存） */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            return $this->fail('商品不存在', 40004);
        }

        $payload = $this->validatePayload($request, forUpdate: true);
        $this->validateAttributeValues($payload);

        try {
            DB::transaction(function () use ($product, $payload) {
                $updates = collect($payload)->except(['skus', 'images', 'specs_selection', 'attribute_values'])->all();
                $product->fill($updates)->save();

                if (array_key_exists('specs_selection', $payload) && $payload['specs_selection'] !== []) {
                    // 新链路：按签名差异合并（保留未变化行的价格/库存/编码）
                    $matrix = $this->attributes->generateSkuMatrix($payload['specs_selection']);
                    $this->attributes->mergeSkuMatrix($product, $matrix, $payload['skus'] ?? []);
                } elseif (array_key_exists('skus', $payload) && $this->hasLegacySpecs($payload['skus'])) {
                    // 旧链路（兼容 V1.0 调用方 / 历史导入数据）：按 sku_code 差异合并，
                    // 不可「全删重建」——软删除的行仍占用 sku_code 唯一索引，重建同编码必冲突
                    $this->replaceSkus($product, $payload['skus']);
                }

                if (array_key_exists('images', $payload)) {
                    $product->images()->delete();
                    $this->saveImages($product, $payload['images']);
                }

                if (array_key_exists('attribute_values', $payload)) {
                    $product->attributeValues()->delete();
                    $this->saveAttributeValues($product, $payload['attribute_values']);
                }

                $product->price = $this->refreshDisplayPrice($product);
                $product->save();
            });
        } catch (QueryException $e) {
            if ($this->isSkuCodeUniqueViolation($e)) {
                throw BusinessException::conflict('SKU 编码已存在，请更换后重试');
            }

            throw $e;
        }

        $this->opLog->record($request->user()?->id, 'product', 'update', 'Product', $id);

        return $this->success(null, '更新成功');
    }

    /**
     * SKU 矩阵预览（V1.1 E01 / T-009）
     * POST /admin/products/sku-matrix  body: { product_id?, specs_selection: [...] }
     */
    public function previewSkuMatrix(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'specs_selection' => ['required', 'array'],
            'specs_selection.*.attribute_id' => ['required', 'integer'],
            'specs_selection.*.values' => ['nullable', 'array'],
            'specs_selection.*.values.*' => ['integer'],
        ]);

        $product = ! empty($data['product_id']) ? Product::find($data['product_id']) : null;

        return $this->success($this->attributes->preview($product, $data['specs_selection']));
    }

    /**
     * 按维度批量设置 SKU（V1.1 E01 / T-009）
     * POST /admin/products/{id}/skus/batch-set
     * body: { attribute?, value?, price_delta?, price?, stock?, status? }
     */
    public function batchSetSkus(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            throw BusinessException::notFound('商品不存在');
        }

        $data = $request->validate([
            'attribute' => ['nullable', 'string', 'max:50'],
            'value' => ['nullable', 'string', 'max:64'],
            'price_delta' => ['nullable', 'numeric'],
            'price' => ['nullable', 'numeric', 'min:0.01'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        if (collect($data)->filter(fn ($v) => $v !== null)->isEmpty()) {
            throw BusinessException::badRequest('请至少指定一项要修改的内容');
        }

        $affected = $this->attributes->batchSet($product, $data);
        $this->opLog->record($request->user()?->id, 'product', 'batch_set_skus', 'Product', $id, $data + ['affected' => $affected]);

        return $this->success(['affected' => $affected], sprintf('已更新 %d 个 SKU', $affected));
    }

    /** 上下架 */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:0,1'],
        ]);

        $product = Product::find($id);
        if (! $product) {
            return $this->fail('商品不存在', 40004);
        }

        // 上架要求至少一个有效 SKU
        if ($data['status'] == 1 && ! $product->skus()->where('status', 1)->exists()) {
            return $this->fail('请先添加有效的 SKU 规格', 40000);
        }

        $product->status = $data['status'];
        $product->save();

        $this->opLog->record($request->user()?->id, 'product', $data['status'] == 1 ? 'on_shelf' : 'off_shelf', 'Product', $id);

        return $this->success(null, $data['status'] == 1 ? '上架成功' : '下架成功');
    }

    /** 批量上下架 */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:on_shelf,off_shelf'],
        ]);

        $count = Product::whereIn('id', $data['ids'])
            ->update(['status' => $data['action'] === 'on_shelf' ? 1 : 0]);

        $this->opLog->record($request->user()?->id, 'product', 'batch_'.$data['action'], 'Product', null, ['count' => $count]);

        return $this->success(['count' => $count], '操作成功');
    }

    // ---------- internals ----------

    private function validatePayload(Request $request, bool $forUpdate = false): array
    {
        $hasSelection = $request->filled('specs_selection');

        $rules = [
            'category_id' => [$forUpdate ? 'sometimes' : 'required', 'nullable', 'integer', 'exists:categories,id'],
            'title' => [$forUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'main_image' => ['nullable', 'string', 'max:512'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:0,1'],
            'sort' => ['nullable', 'integer'],
            // V1.1 E01 新增字段
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'weight' => ['nullable', 'integer', 'min:0'],
            'video_url' => ['nullable', 'string', 'max:512'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'attribute_values' => ['nullable', 'array'],
            'attribute_values.*.attribute_id' => ['required', 'integer'],
            'attribute_values.*.value' => ['required', 'string', 'max:255'],
            // V1.1 E01：勾选规格 → 服务端生成 SKU 矩阵
            'specs_selection' => ['nullable', 'array'],
            'specs_selection.*.attribute_id' => ['required', 'integer'],
            'specs_selection.*.values' => ['nullable', 'array'],
            'specs_selection.*.values.*' => ['integer'],
            // 旧结构（兼容）：skus[].specs 直接给全量行
            'skus' => [$forUpdate ? 'sometimes' : ($hasSelection ? 'nullable' : 'required'), 'array'],
            'skus.*.signature' => ['nullable', 'string', 'max:255'],
            'skus.*.sku_code' => ['nullable', 'string', 'max:64'],
            'skus.*.specs' => ['nullable', 'array'],
            'skus.*.price' => ['nullable', 'numeric', 'min:0.01'],
            'skus.*.stock' => ['nullable', 'integer', 'min:0'],
            'skus.*.status' => ['nullable', 'in:0,1'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:512'],
        ];

        if (! $hasSelection) {
            // 非矩阵链路：价格必填（保持 V1.0 校验语义）
            $rules['skus.*.price'] = ['required', 'numeric', 'min:0.01'];
            $rules['skus.*.stock'] = ['required', 'integer', 'min:0'];
        }

        return $request->validate($rules);
    }

    /** 是否使用旧结构（行内直接带 specs） */
    private function hasLegacySpecs(array $skus): bool
    {
        foreach ($skus as $sku) {
            if (! empty($sku['specs'])) {
                return true;
            }
        }

        return false;
    }

    /** 创建/更新时按链路持久化 SKU */
    private function persistSkus(Product $product, array $payload): void
    {
        if (! empty($payload['specs_selection'])) {
            $matrix = $this->attributes->generateSkuMatrix($payload['specs_selection']);
            $this->attributes->mergeSkuMatrix($product, $matrix, $payload['skus'] ?? []);

            return;
        }

        $this->saveSkus($product, $payload['skus']);
    }

    /** 商品展示价（矩阵链路给临时价，随后由 refreshDisplayPrice 回写） */
    private function resolveProductPrice(array $payload): string
    {
        if (! empty($payload['specs_selection'])) {
            return '0.00';
        }

        return $this->minSkuPrice($payload['skus']);
    }

    /** 以最低有效 SKU 价作为商品展示价 */
    private function refreshDisplayPrice(Product $product): string
    {
        $min = $product->skus()->where('status', 1)->min('price');
        if ($min === null) {
            $min = $product->skus()->min('price');
        }

        return bcadd((string) ($min ?? $product->price), '0', 2);
    }

    /**
     * 保存参数类属性值（V1.1 E01 / T-008）
     *
     * @param  array<int, array{attribute_id:int, value:string}>  $rows
     */
    private function saveAttributeValues(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            ProductAttributeValue::create([
                'product_id' => $product->id,
                'attribute_id' => (int) $row['attribute_id'],
                'value' => (string) $row['value'],
            ]);
        }
    }

    /**
     * 校验商品参数（V1.1 E01 / T-008 步骤 6）
     *
     * - attribute_id 必须属于该分类的属性模板
     * - 模板中的必填属性不能缺失
     * - 值必须在 attribute_values 合法集合内（allow_custom=true 时允许自由文本）
     */
    private function validateAttributeValues(array $payload): void
    {
        $categoryId = $payload['category_id'] ?? null;
        $rows = $payload['attribute_values'] ?? [];

        if (! $categoryId) {
            if ($rows !== []) {
                throw BusinessException::badRequest('请先选择商品分类，再填写商品参数');
            }

            return;
        }

        $template = CategoryAttribute::where('category_id', $categoryId)->get();
        $templateIds = $template->pluck('attribute_id')->all();

        if ($template->isEmpty()) {
            if ($rows !== []) {
                throw BusinessException::badRequest('该分类尚未配置属性模板，无法填写商品参数');
            }

            return;
        }

        // 提交的属性必须在模板中
        foreach ($rows as $row) {
            if (! in_array((int) $row['attribute_id'], $templateIds, true)) {
                $name = Attribute::whereKey($row['attribute_id'])->value('name') ?? $row['attribute_id'];
                throw BusinessException::badRequest(sprintf('属性「%s」不在该分类的属性模板中', $name));
            }
        }

        // 必填属性不能缺失
        $submitted = array_map(fn ($r) => (int) $r['attribute_id'], $rows);
        $missing = [];
        foreach ($template as $item) {
            if ($item->is_required && ! in_array((int) $item->attribute_id, $submitted, true)) {
                $missing[] = Attribute::whereKey($item->attribute_id)->value('name') ?? $item->attribute_id;
            }
        }
        if ($missing !== []) {
            throw BusinessException::badRequest('以下必填属性未填写：'.implode('、', $missing));
        }

        // 值合法性
        $attributes = Attribute::whereIn('id', array_unique($submitted))->get()->keyBy('id');
        foreach ($rows as $row) {
            /** @var Attribute|null $attribute */
            $attribute = $attributes->get((int) $row['attribute_id']);
            if (! $attribute) {
                throw BusinessException::badRequest('属性不存在：'.$row['attribute_id']);
            }
            if ($attribute->allow_custom) {
                continue;
            }

            $valid = AttributeValue::where('attribute_id', $attribute->id)
                ->where('value', $row['value'])
                ->exists();

            if (! $valid) {
                throw BusinessException::badRequest(sprintf('属性「%s」的值「%s」不合法', $attribute->name, $row['value']));
            }
        }
    }

    private function minSkuPrice(array $skus): string
    {
        return bcadd((string) min(array_column($skus, 'price')), '0', 2);
    }

    /**
     * 旧结构 SKU 差异合并（按 sku_code 命中已有行 → 原地更新，未提交的旧行再软删）
     *
     * 注意：`product_skus` 走软删除，被删除的行仍留在表内，「sku_code」唯一索引对它们同样生效。
     * 因此旧实现「先 delete 再 create 同编码」在 PostgreSQL 下必然抛 SQLSTATE[23505]。
     *
     * @param  array<int, array{sku_code?:string|null, specs?:array, price:mixed, stock?:int, status?:int}>  $skus
     */
    private function replaceSkus(Product $product, array $skus): void
    {
        $skus = array_values($skus);

        // 同一次提交内不允许出现重复编码（否则第二行会覆盖第一行）
        $codes = collect($skus)
            ->map(fn ($sku) => trim((string) ($sku['sku_code'] ?? '')))
            ->filter()
            ->values();
        if ($codes->duplicates()->isNotEmpty()) {
            throw BusinessException::conflict('SKU 编码存在重复，请修改后重试');
        }

        $existing = $product->skus()->get()->keyBy('sku_code');
        $keptIds = [];

        foreach ($skus as $sku) {
            $code = trim((string) ($sku['sku_code'] ?? ''));
            $attrs = [
                'specs' => $sku['specs'] ?? [],
                'price' => bcadd((string) $sku['price'], '0', 2),
                'status' => isset($sku['status']) ? (int) $sku['status'] : 1,
            ];
            $stock = max(0, (int) ($sku['stock'] ?? 0));

            $model = $code !== '' ? $existing->get($code) : null;

            if ($model) {
                // 命中已有行：id / 编码保持不变，订单引用与库存流水不受影响
                $model->fill($attrs)->save();
            } else {
                $code = $code !== '' ? $code : $this->generateSkuCode($product);
                $this->assertSkuCodeAvailable($code);

                $model = ProductSku::create([
                    'product_id' => $product->id,
                    'sku_code' => $code,
                    ...$attrs,
                ]);

                Inventory::create(['sku_id' => $model->id, 'stock' => $stock]);
                $keptIds[] = $model->id;

                continue;
            }

            Inventory::updateOrCreate(['sku_id' => $model->id], ['stock' => $stock]);
            $keptIds[] = $model->id;
        }

        // 本次未提交的存量行：库存清零后软删除
        foreach ($product->skus()->whereNotIn('id', $keptIds ?: [0])->get() as $stale) {
            Inventory::updateOrCreate(['sku_id' => $stale->id], ['stock' => 0]);
            $stale->delete();
        }
    }

    private function saveSkus(Product $product, array $skus): void
    {
        foreach (array_values($skus) as $sku) {
            $code = trim((string) ($sku['sku_code'] ?? ''));
            $code = $code !== '' ? $code : $this->generateSkuCode($product);
            $this->assertSkuCodeAvailable($code);

            $skuModel = ProductSku::create([
                'product_id' => $product->id,
                'sku_code' => $code,
                'specs' => $sku['specs'] ?? [],
                'price' => $sku['price'],
                'status' => $sku['status'] ?? 1,
            ]);

            Inventory::create([
                'sku_id' => $skuModel->id,
                'stock' => max(0, (int) $sku['stock']),
            ]);
        }
    }

    /** 生成商品内自增且全表未占用的 SKU 编码（软删除行同样计入占用） */
    private function generateSkuCode(Product $product): string
    {
        $seq = ProductSku::withTrashed()->where('product_id', $product->id)->count();

        do {
            $seq++;
            $code = sprintf('CS-%d-%d', $product->id, $seq);
        } while (ProductSku::withTrashed()->where('sku_code', $code)->exists());

        return $code;
    }

    /** 编码占用校验（含软删除历史行） */
    private function assertSkuCodeAvailable(string $code): void
    {
        if (ProductSku::withTrashed()->where('sku_code', $code)->exists()) {
            throw BusinessException::conflict(sprintf('SKU 编码「%s」已被占用，请更换后重试', $code));
        }
    }

    /**
     * SKU 编码唯一约束冲突判定
     * PostgreSQL：SQLSTATE 23505（索引名 product_skus_sku_code_unique）
     * SQLite：UNIQUE constraint failed: product_skus.sku_code
     */
    private function isSkuCodeUniqueViolation(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return (str_contains($msg, 'UNIQUE constraint failed') || (int) ($e->errorInfo[1] ?? 0) === 23505)
            && str_contains($msg, 'sku_code');
    }

    private function saveImages(Product $product, array $images): void
    {
        foreach (array_values($images) as $index => $url) {
            ProductImage::create([
                'product_id' => $product->id,
                'url' => $url,
                'sort' => $index,
            ]);
        }
    }

    /** 详情格式化：SKU 附带库存（V1.1：品牌、参数、规格矩阵） */
    private function formatProduct(Product $product): array
    {
        // 规格矩阵：维度 + 值（供后台表单回显勾选框）
        $specsSelection = [];
        foreach ($product->skus as $sku) {
            foreach ((array) ($sku->specs ?? []) as $name => $value) {
                $specsSelection[$name][$value] = true;
            }
        }
        $specsSelectionOut = [];
        foreach ($specsSelection as $name => $values) {
            $attribute = Attribute::where('name', $name)->first();
            $specsSelectionOut[] = [
                'attribute_id' => $attribute?->id,
                'name' => $name,
                'value_names' => array_keys($values),
            ];
        }

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'category' => $product->category?->only(['id', 'name']),
            'title' => $product->title,
            'subtitle' => $product->subtitle,
            'main_image' => $product->main_image,
            'images' => $product->images->sortBy('sort')->pluck('url')->values(),
            'description' => $product->description,
            'price' => $product->price,
            'status' => (int) $product->status,
            'sales_count' => $product->sales_count,
            'sort' => $product->sort,
            // V1.1 E01
            'brand_id' => $product->brand_id,
            'brand' => $product->brand?->only(['id', 'name']),
            'weight' => (int) $product->weight,
            'video_url' => $product->video_url,
            'keywords' => $product->keywords,
            'attribute_values' => $product->attributeValues->map(fn (ProductAttributeValue $v) => [
                'attribute_id' => $v->attribute_id,
                'attribute_name' => $v->attribute?->name,
                'value' => $v->value,
            ])->values()->all(),
            'specs_selection' => $specsSelectionOut,
            'skus' => $product->skus->map(fn ($sku) => [
                'id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'specs' => $sku->specs,
                'signature' => ProductAttributeService::signature((array) ($sku->specs ?? [])),
                'price' => $sku->price,
                'stock' => $sku->inventory?->stock ?? 0,
                'status' => (int) $sku->status,
            ])->values(),
        ];
    }
}
