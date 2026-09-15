<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSku;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
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

    public function __construct(private OperationLogService $opLog)
    {
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
        $product = Product::with(['skus.inventory', 'images', 'category:id,name'])->find($id);
        if (! $product) {
            return $this->fail('商品不存在', 40004);
        }

        return $this->success($this->formatProduct($product));
    }

    /** 创建商品（含 SKU、多图） */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);

        $product = DB::transaction(function () use ($payload) {
            $product = Product::create([
                ...collect($payload)->except(['skus', 'images'])->all(),
                'price' => $this->minSkuPrice($payload['skus']),
            ]);

            $this->saveSkus($product, $payload['skus']);
            $this->saveImages($product, $payload['images'] ?? []);

            return $product;
        });

        $this->opLog->record(request()->user()?->id, 'product', 'create', 'Product', $product->id);

        return $this->success(['id' => $product->id], '创建成功');
    }

    /** 更新商品（全量替换 SKU 与图片） */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            return $this->fail('商品不存在', 40004);
        }

        $payload = $this->validatePayload($request, forUpdate: true);

        DB::transaction(function () use ($product, $payload) {
            $product->fill([
                ...collect($payload)->except(['skus', 'images'])->all(),
                'price' => $this->minSkuPrice($payload['skus']),
            ])->save();

            // 全量替换 SKU：删除旧的（软删），重建库存
            $oldSkuIds = $product->skus()->pluck('id');
            Inventory::whereIn('sku_id', $oldSkuIds)->delete();
            $product->skus()->delete();

            $this->saveSkus($product, $payload['skus']);

            if (array_key_exists('images', $payload)) {
                $product->images()->delete();
                $this->saveImages($product, $payload['images']);
            }
        });

        $this->opLog->record($request->user()?->id, 'product', 'update', 'Product', $id);

        return $this->success(null, '更新成功');
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
        $rules = [
            'category_id' => [$forUpdate ? 'sometimes' : 'required', 'nullable', 'integer', 'exists:categories,id'],
            'title' => [$forUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'main_image' => ['nullable', 'string', 'max:512'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:0,1'],
            'sort' => ['nullable', 'integer'],
            'skus' => [$forUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            'skus.*.sku_code' => ['nullable', 'string', 'max:64'],
            'skus.*.specs' => ['nullable', 'array'],
            'skus.*.price' => ['required', 'numeric', 'min:0.01'],
            'skus.*.stock' => ['required', 'integer', 'min:0'],
            'skus.*.status' => ['nullable', 'in:0,1'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:512'],
        ];

        return $request->validate($rules);
    }

    private function minSkuPrice(array $skus): string
    {
        return bcadd((string) min(array_column($skus, 'price')), '0', 2);
    }

    private function saveSkus(Product $product, array $skus): void
    {
        foreach ($skus as $index => $sku) {
            $skuModel = ProductSku::create([
                'product_id' => $product->id,
                'sku_code' => $sku['sku_code'] ?? sprintf('CS-%d-%d', $product->id, $index + 1),
                'specs' => $sku['specs'] ?? [],
                'price' => $sku['price'],
                'status' => $sku['status'] ?? 1,
            ]);

            Inventory::create([
                'sku_id' => $skuModel->id,
                'stock' => (int) $sku['stock'],
            ]);
        }
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

    /** 详情格式化：SKU 附带库存 */
    private function formatProduct(Product $product): array
    {
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
            'skus' => $product->skus->map(fn ($sku) => [
                'id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'specs' => $sku->specs,
                'price' => $sku->price,
                'stock' => $sku->inventory?->stock ?? 0,
                'status' => (int) $sku->status,
            ])->values(),
        ];
    }
}
