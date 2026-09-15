<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 分类属性模板（V1.1 E01 / T-008）
 *
 * 定义某分类下的商品需要填写/勾选哪些属性，商品表单据此动态渲染。
 * 权限：product.update
 */
class CategoryAttributeController extends Controller
{
    use ApiResponse;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 读取分类当前模板 */
    public function show(int $categoryId): JsonResponse
    {
        $category = Category::find($categoryId);
        if (! $category) {
            throw BusinessException::notFound('分类不存在');
        }

        $rows = CategoryAttribute::with('attribute')
            ->where('category_id', $categoryId)
            ->orderByDesc('sort')->orderBy('id')
            ->get()
            ->filter(fn (CategoryAttribute $row) => $row->attribute !== null)
            ->map(fn (CategoryAttribute $row) => [
                'attribute_id' => $row->attribute_id,
                'name' => $row->attribute->name,
                'type' => $row->attribute->type,
                'is_filterable' => $row->attribute->is_filterable,
                'is_required' => $row->is_required,
                'sort' => $row->sort,
            ])
            ->values();

        return $this->success([
            'category_id' => $categoryId,
            'category_name' => $category->name,
            'attributes' => $rows,
        ]);
    }

    /**
     * 覆盖保存分类模板
     * PUT body: { attributes: [{ attribute_id, is_required?, sort? }] }
     */
    public function update(Request $request, int $categoryId): JsonResponse
    {
        $category = Category::find($categoryId);
        if (! $category) {
            throw BusinessException::notFound('分类不存在');
        }

        $data = $request->validate([
            'attributes' => ['present', 'array'],
            'attributes.*.attribute_id' => ['required', 'integer'],
            'attributes.*.is_required' => ['nullable', 'boolean'],
            'attributes.*.sort' => ['nullable', 'integer'],
        ]);

        $attributeIds = collect($data['attributes'])->pluck('attribute_id')->unique()->values()->all();

        // 校验属性存在性
        $found = Attribute::whereIn('id', $attributeIds)->pluck('id')->all();
        $missing = array_values(array_diff($attributeIds, $found));
        if ($missing !== []) {
            throw BusinessException::badRequest('存在无效的属性 ID：'.implode(',', $missing));
        }

        DB::transaction(function () use ($categoryId, $data) {
            // 覆盖语义：先删除不在新列表中的关联
            $keep = collect($data['attributes'])->pluck('attribute_id')->all();
            if ($keep === []) {
                CategoryAttribute::where('category_id', $categoryId)->delete();
            } else {
                CategoryAttribute::where('category_id', $categoryId)->whereNotIn('attribute_id', $keep)->delete();
            }

            foreach ($data['attributes'] as $idx => $row) {
                CategoryAttribute::updateOrCreate(
                    ['category_id' => $categoryId, 'attribute_id' => $row['attribute_id']],
                    [
                        'is_required' => $row['is_required'] ?? false,
                        'sort' => $row['sort'] ?? (count($data['attributes']) - $idx),
                    ],
                );
            }
        });

        $this->opLog->record($request->user()?->id, 'category_attribute', 'update', 'Category', $categoryId, [
            'attributes' => $attributeIds,
        ]);

        return $this->success(null, '模板保存成功');
    }
}
