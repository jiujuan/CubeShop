<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 分类管理（API 文档 8.2）
 * 权限：category.manage
 */
class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 分类树（含禁用，管理端使用） */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get();

        return $this->success($this->toTree($categories));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:128'],
            'sort' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        $parentId = $data['parent_id'] ?? 0;
        if ($parentId > 0) {
            $parent = Category::find($parentId);
            if (! $parent) {
                return $this->fail('父分类不存在', 40004);
            }
            if ($parent->parent_id > 0) {
                return $this->fail('仅支持两级分类', 40000);
            }
        }

        $category = Category::create([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'sort' => $data['sort'] ?? 0,
            'status' => $data['status'] ?? 1,
        ]);

        $this->opLog->record($request->user()?->id, 'category', 'create', 'Category', $category->id);

        return $this->success($category, '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);
        if (! $category) {
            return $this->fail('分类不存在', 40004);
        }

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:0'],
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'sort' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        if (isset($data['parent_id']) && $data['parent_id'] > 0) {
            if ($data['parent_id'] == $id) {
                return $this->fail('父分类不能是自身', 40000);
            }
            $parent = Category::find($data['parent_id']);
            if (! $parent) {
                return $this->fail('父分类不存在', 40004);
            }
            if ($parent->parent_id > 0 || $category->children()->exists()) {
                return $this->fail('仅支持两级分类', 40000);
            }
        }

        $category->fill($data)->save();

        $this->opLog->record($request->user()?->id, 'category', 'update', 'Category', $category->id);

        return $this->success($category, '更新成功');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);
        if (! $category) {
            return $this->fail('分类不存在', 40004);
        }

        if ($category->children()->exists()) {
            return $this->fail('请先删除子分类', 40000);
        }
        if (\App\Models\Product::where('category_id', $id)->exists()) {
            return $this->fail('该分类下存在商品，不能删除', 40000);
        }

        $category->delete();

        $this->opLog->record($request->user()?->id, 'category', 'delete', 'Category', $id);

        return $this->success(null, '删除成功');
    }

    /** 组装两级树 */
    private function toTree($categories): array
    {
        $roots = $categories->where('parent_id', 0)->values();

        return $roots->map(function ($root) use ($categories) {
            $children = $categories->where('parent_id', $root->id)->values();

            return [
                'id' => $root->id,
                'parent_id' => 0,
                'name' => $root->name,
                'sort' => $root->sort,
                'status' => (int) $root->status,
                'children' => $children->map(fn ($c) => [
                    'id' => $c->id,
                    'parent_id' => $c->parent_id,
                    'name' => $c->name,
                    'sort' => $c->sort,
                    'status' => (int) $c->status,
                ])->all(),
            ];
        })->all();
    }
}
