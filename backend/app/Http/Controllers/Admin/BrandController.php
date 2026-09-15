<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 品牌管理（V1.1 E01 / T-008）
 * 权限：product.update
 */
class BrandController extends Controller
{
    use ApiResponse;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 品牌列表（管理端，含停用） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Brand::query()
            ->when($data['keyword'] ?? null, fn ($q, $kw) => $q->where('name', 'like', '%'.$kw.'%'))
            ->when(isset($data['status']), fn ($q) => $q->where('status', (int) $data['status']))
            ->withCount('products')
            ->orderByDesc('sort')->orderBy('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (Brand $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'logo' => $b->logo,
            'sort' => $b->sort,
            'status' => (int) $b->status,
            'product_count' => (int) $b->products_count,
            'created_at' => $b->created_at?->format('Y-m-d H:i:s'),
        ]);

        return $this->paginated($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:brands,name'],
            'logo' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        $brand = Brand::create($data + ['status' => $data['status'] ?? 1]);

        $this->opLog->record($request->user()?->id, 'brand', 'create', 'Brand', $brand->id, ['name' => $brand->name]);

        return $this->success(['id' => $brand->id], '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $brand = Brand::find($id);
        if (! $brand) {
            throw BusinessException::notFound('品牌不存在');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64', 'unique:brands,name,'.$id],
            'logo' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        $before = $brand->only(['name', 'logo', 'sort', 'status']);
        $brand->fill($data)->save();

        $this->opLog->record($request->user()?->id, 'brand', 'update', 'Brand', $id, [
            'before' => $before,
            'after' => $brand->only(['name', 'logo', 'sort', 'status']),
        ]);

        return $this->success(null, '更新成功');
    }

    /** 删除（被商品引用时拒绝并提示数量） */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $brand = Brand::find($id);
        if (! $brand) {
            throw BusinessException::notFound('品牌不存在');
        }

        $used = Product::where('brand_id', $id)->count();
        if ($used > 0) {
            throw BusinessException::conflict(sprintf('该品牌已被 %d 个商品引用，无法删除', $used));
        }

        $brand->delete();
        $this->opLog->record($request->user()?->id, 'brand', 'delete', 'Brand', $id, ['name' => $brand->name]);

        return $this->success(null, '删除成功');
    }
}
