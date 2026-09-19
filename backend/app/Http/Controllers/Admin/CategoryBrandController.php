<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryBrand;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 分类可选品牌（分类 ↔ 品牌 多对多，2026-09-19）
 *
 * 品牌与分类是两个正交维度、互不隶属：一个品牌可挂在多个分类下，一个分类下可有多个品牌。
 * 后台在此配置「该分类可选哪些品牌」，前台按分类浏览时据此收敛品牌范围。
 * 权限：读 product.view、写 product.update（与分类属性模板保持一致）
 */
class CategoryBrandController extends Controller
{
    use ApiResponse;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 读取分类已挂品牌 */
    public function show(int $categoryId): JsonResponse
    {
        $category = Category::find($categoryId);
        if (! $category) {
            throw BusinessException::notFound('分类不存在');
        }

        $rows = CategoryBrand::with('brand')
            ->where('category_id', $categoryId)
            ->orderByDesc('sort')->orderBy('id')
            ->get()
            ->filter(fn (CategoryBrand $row) => $row->brand !== null)
            ->map(fn (CategoryBrand $row) => [
                'brand_id' => $row->brand_id,
                'name' => $row->brand->name,
                'logo' => $row->brand->logo,
                'status' => (int) $row->brand->status,
                'sort' => $row->sort,
            ])
            ->values();

        return $this->success([
            'category_id' => $categoryId,
            'category_name' => $category->name,
            'brands' => $rows,
        ]);
    }

    /**
     * 覆盖保存分类可选品牌
     * PUT body: { brands: [{ brand_id, sort? }] }
     */
    public function update(Request $request, int $categoryId): JsonResponse
    {
        $category = Category::find($categoryId);
        if (! $category) {
            throw BusinessException::notFound('分类不存在');
        }

        $data = $request->validate([
            'brands' => ['present', 'array'],
            'brands.*.brand_id' => ['required', 'integer'],
            'brands.*.sort' => ['nullable', 'integer'],
        ]);

        $brandIds = collect($data['brands'])->pluck('brand_id')->unique()->values()->all();

        // 校验品牌存在性
        $found = Brand::whereIn('id', $brandIds)->pluck('id')->all();
        $missing = array_values(array_diff($brandIds, $found));
        if ($missing !== []) {
            throw BusinessException::badRequest('存在无效的品牌 ID：'.implode(',', $missing));
        }

        DB::transaction(function () use ($categoryId, $data) {
            // 覆盖语义：先删除不在新列表中的关联
            $keep = collect($data['brands'])->pluck('brand_id')->all();
            if ($keep === []) {
                CategoryBrand::where('category_id', $categoryId)->delete();
            } else {
                CategoryBrand::where('category_id', $categoryId)->whereNotIn('brand_id', $keep)->delete();
            }

            foreach ($data['brands'] as $idx => $row) {
                CategoryBrand::updateOrCreate(
                    ['category_id' => $categoryId, 'brand_id' => $row['brand_id']],
                    ['sort' => $row['sort'] ?? (count($data['brands']) - $idx)],
                );
            }
        });

        $this->opLog->record($request->user()?->id, 'category_brand', 'update', 'Category', $categoryId, [
            'brands' => $brandIds,
        ]);

        return $this->success(null, '品牌配置保存成功');
    }
}
