<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\SysOperationLog;
use App\Services\Common\MediaRegistry;
use App\Support\ApiResponse;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台媒体库（图片资产治理 P2，权限 media.*）
 *
 * 消费 `media_files` 旁路登记表：浏览 / 上传 / 改名 / 替换 / 软删。
 * 设计详见 docs/design/CubeShop_Media_Library_v1.0.md §6。
 *
 * ⚠️ 三条硬约束（与 P0/P1 的保守策略一脉相承）：
 * 1. 删除只做**软删**（进 30 天回收窗口），物理删除由 `media:prune --force` 人工执行；
 * 2. `usage_count > 0` 时**拒绝删除** —— 不想让任何人从 UI 上弄坏还在展示的图；
 * 3. 替换（replace）**保留原 path**，因此所有引用方自动生效，不动任何业务表。
 */
class MediaController extends Controller
{
    use ApiResponse;

    /** 排序字段白名单（不让前端拼列名） */
    private const SORTS = [
        'latest' => ['id', 'desc'],
        'oldest' => ['id', 'asc'],
        'largest' => ['size', 'desc'],
        'name' => ['original_name', 'asc'],
    ];

    public function __construct(private readonly MediaRegistry $registry)
    {
    }

    /** GET /api/admin/media —— 分页检索 */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:32'],
            'unused' => ['nullable', 'boolean'],
            'size_from' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $keyword = trim((string) ($data['keyword'] ?? ''));
        [$column, $direction] = self::SORTS[$data['sort'] ?? 'latest'] ?? self::SORTS['latest'];

        $paginator = MediaFile::query()
            ->when($keyword !== '', fn ($q) => $q->where(function ($q) use ($keyword) {
                $q->where('original_name', 'like', '%'.$keyword.'%')
                    ->orWhere('path', 'like', '%'.$keyword.'%');
            }))
            ->when(! empty($data['module']), fn ($q) => $q->where('module', $data['module']))
            ->when(! empty($data['unused']), fn ($q) => $q->where('usage_count', '<=', 0))
            ->when(isset($data['size_from']), fn ($q) => $q->where('size', '>=', (int) $data['size_from']))
            ->orderBy($column, $direction)
            ->paginate((int) ($data['per_page'] ?? 24));

        return $this->success([
            'list' => collect($paginator->items())->map(fn (MediaFile $m) => $this->toArray($m))->all(),
            'modules' => $this->modules(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /** POST /api/admin/media —— 上传并登记 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'], // 5MB
            'module' => ['nullable', 'string', 'max:32'],
        ]);

        $module = ($data['module'] ?? '') ?: 'common';
        $result = $this->registry->registerUpload($data['file'], $module, $request->user()?->id);

        if ($result['media'] !== null) {
            $this->log($request, 'media_upload', $result['media']->id,
                '上传图片 '.$result['media']->displayName().'（'.($result['reused'] ? '命中 md5 去重，复用已有文件' : '新文件').'）');
        }

        return $this->success([
            'url' => $result['url'],
            'path' => $result['path'],
            'reused' => $result['reused'],
            'media' => $result['media'] === null ? null : $this->toArray($result['media']),
        ], $result['reused'] ? '已上传（内容与已有图片重复，直接复用）' : '上传成功', 201);
    }

    /** PATCH /api/admin/media/{id} —— 改名 / 换模块 */
    public function update(Request $request, int $id): JsonResponse
    {
        $media = MediaFile::findOrFail($id);

        $data = $request->validate([
            'original_name' => ['sometimes', 'string', 'max:255'],
            'module' => ['sometimes', 'string', 'max:32'],
        ]);

        $media->update($data);
        $this->log($request, 'media_update', $media->id, '编辑图片信息 '.$media->displayName());

        return $this->success($this->toArray($media->fresh() ?? $media), '已更新');
    }

    /**
     * POST /api/admin/media/{id}/replace —— 替换文件、保留 path
     *
     * 换图不破引用的关键：业务表里的相对路径一字不改，所有引用方自动生效。
     */
    public function replace(Request $request, int $id): JsonResponse
    {
        $media = MediaFile::findOrFail($id);

        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'],
        ]);

        try {
            $result = $this->registry->replaceFile($media, $data['file']);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('替换失败，请稍后重试');
        }

        $this->log($request, 'media_replace', $media->id,
            '替换图片 '.$media->displayName().'（保留路径，引用方自动生效）');

        return $this->success([
            'url' => $result['url'],
            'path' => $result['media']->path,
            'media' => $this->toArray($result['media']),
            // 同 md5 复用者也会被这次替换影响，回传给前端提示（P0 去重的既有代价）
            'reused_paths' => $result['reused_paths'],
        ], '已替换（路径未变，所有引用自动生效）');
    }

    /** DELETE /api/admin/media/{id} —— 软删（在用则拒绝） */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $media = MediaFile::findOrFail($id);

        if ($media->usage_count > 0) {
            return $this->fail('该图片仍被 '.$media->usage_count.' 处引用，不能删除。请先解除引用（替换业务图）后再操作。');
        }

        $media->delete();
        $this->log($request, 'media_delete', $media->id,
            '删除图片 '.$media->displayName().'（软删进 30 天回收窗口，物理文件仍在）');

        return $this->success(null, '已删除（30 天内可恢复，物理文件暂留）');
    }

    /** 模块字典：供前端下拉筛选 */
    private function modules(): array
    {
        return MediaFile::query()
            ->select('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->filter(fn ($m) => is_string($m) && $m !== '')
            ->values()
            ->all();
    }

    private function toArray(MediaFile $m): array
    {
        return [
            'id' => $m->id,
            'path' => $m->path,
            // 出口才拼域名：库里永远是 uploads/... 相对路径
            'url' => MediaUrl::to($m->path) ?? '',
            'original_name' => $m->original_name,
            'module' => $m->module,
            'mime' => $m->mime,
            'size' => (int) $m->size,
            'size_human' => $this->humanSize((int) $m->size),
            'width' => $m->width,
            'height' => $m->height,
            'usage_count' => (int) $m->usage_count,
            'exists_on_disk' => $m->existsOnDisk(),
            'created_at' => $m->created_at?->toDateTimeString(),
            'updated_at' => $m->updated_at?->toDateTimeString(),
        ];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }

    private function log(Request $request, string $action, int $targetId, string $content): void
    {
        SysOperationLog::create([
            'user_id' => $request->user()->id,
            'actor_type' => SysOperationLog::ACTOR_ADMIN,
            'module' => 'media',
            'action' => $action,
            'target_type' => 'media_files',
            'target_id' => $targetId,
            'content' => $content,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
