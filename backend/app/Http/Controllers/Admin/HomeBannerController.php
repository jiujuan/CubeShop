<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomeBanner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台首页广告位管理（P-HomeBanner，权限 home.manage）
 *
 * 统一维护首页主轮播图（banner）、中部广告宫格（promo）、底部广告图（bottom），
 * 每条记录可编辑图片/大标题/小标题/跳转链接/排序/启用状态。
 * 写操作记 sys_operation_log（module=home_banner）。
 */
class HomeBannerController extends Controller
{
    use ApiResponse;

    /** GET /api/admin/home-banners —— 列表（筛选 + 分页） */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'position' => ['nullable', 'string', 'in:banner,promo,bottom'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = HomeBanner::query()
            ->when(! empty($data['position']), fn ($q) => $q->where('position', $data['position']))
            ->when(! empty($data['keyword']), fn ($q) => $q->where(function ($q) use ($data) {
                $q->where('title', 'like', '%'.$data['keyword'].'%')
                    ->orWhere('subtitle', 'like', '%'.$data['keyword'].'%');
            }))
            ->orderBy('position')->orderBy('sort_order')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        $list = collect($paginator->items())->map(fn (HomeBanner $b) => $this->toArray($b))->all();

        return $this->success([
            'list' => $list,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /** POST /api/admin/home-banners —— 新建 */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $data['is_enabled'] = $data['is_enabled'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['created_by'] = $request->user()->id;

        $banner = HomeBanner::create($data);
        $this->log($request, 'home_banner_create', 'home_banners', $banner->id, '新增广告位 '.$banner->positionLabel().'：'.$banner->title);

        return $this->success($this->toArray($banner), '已创建', 201);
    }

    /** PUT /api/admin/home-banners/{id} —— 编辑 */
    public function update(Request $request, int $id): JsonResponse
    {
        $banner = HomeBanner::findOrFail($id);

        $data = $this->validated($request, sometimes: true);
        $banner->update($data);
        $this->log($request, 'home_banner_update', 'home_banners', $id, '编辑广告位 '.$banner->positionLabel().'：'.$banner->title);

        return $this->success($this->toArray($banner), '已更新');
    }

    /** POST /api/admin/home-banners/{id}/toggle —— 启用/停用 */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $banner = HomeBanner::findOrFail($id);
        $banner->update(['is_enabled' => ! $banner->is_enabled]);
        $this->log($request, 'home_banner_toggle', 'home_banners', $id,
            ($banner->is_enabled ? '启用' : '停用').'广告位 '.$banner->positionLabel().'：'.$banner->title);

        return $this->success($this->toArray($banner), $banner->is_enabled ? '已启用' : '已停用');
    }

    /** DELETE /api/admin/home-banners/{id} —— 删除 */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $banner = HomeBanner::findOrFail($id);
        $banner->delete();
        $this->log($request, 'home_banner_delete', 'home_banners', $id, '删除广告位 '.$banner->positionLabel().'：'.$banner->title);

        return $this->success(null, '已删除');
    }

    /** store/update 共用校验规则 */
    private function validated(Request $request, bool $sometimes = false): array
    {
        $rules = [
            'position' => ['required', 'string', 'in:banner,promo,bottom'],
            'image' => ['required', 'string', 'max:500'],
            'title' => ['required', 'string', 'max:64'],
            'subtitle' => ['nullable', 'string', 'max:128'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_enabled' => ['nullable', 'boolean'],
        ];
        if ($sometimes) {
            $rules = array_map(fn (array $r) => array_merge(['sometimes'], $r), $rules);
        }

        return $request->validate($rules);
    }

    /** 列表/详情统一输出（管理端用 int 主键） */
    private function toArray(HomeBanner $b): array
    {
        return [
            'id' => $b->id,
            'position' => $b->position,
            'position_label' => $b->positionLabel(),
            'image' => $b->image,
            'title' => $b->title,
            'subtitle' => $b->subtitle,
            'link_url' => $b->link_url,
            'sort_order' => $b->sort_order,
            'is_enabled' => (bool) $b->is_enabled,
            'created_at' => $b->created_at?->toDateTimeString(),
            'updated_at' => $b->updated_at?->toDateTimeString(),
        ];
    }

    private function log(Request $request, string $action, string $targetType, int $targetId, string $content): void
    {
        \App\Models\SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => \App\Models\SysOperationLog::ACTOR_ADMIN,
            'module' => 'home_banner',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content' => $content,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
