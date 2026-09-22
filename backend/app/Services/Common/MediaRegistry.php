<?php

namespace App\Services\Common;

use App\Models\Brand;
use App\Models\CsFaqArticle;
use App\Models\CsTicketMessage;
use App\Models\HomeBanner;
use App\Models\MediaFile;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Refund;
use App\Models\Review;
use App\Models\SysUser;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 图片资产注册表：旁路索引的**唯一读写入口**（媒体治理 P0）
 *
 * 职责边界（设计见 docs/design/CubeShop_Media_Library_v1.0.md §2.2）：
 * - 业务表**不引用** media_files.id，两边只以 `path` 关联；
 * - 本服务负责：上传登记 / md5 去重 / 引用计数扫描 / 回收窗口管理。
 *
 * ⚠️ `usage_count` 是**扫描得出的非实时值**，永远不可被当作「此刻有多少地方在用」来信任。
 * 因此基于它的回收被设计成三段式：
 *
 *   1. `reclaimUnused()`：引用归零 → **软删**（`deleted_at` 是 30 天窗口的起点，文件仍在盘上）
 *   2. `restoreIfReferenced()`：扫描发现又被引用了 → **自动恢复**（安全兜底）
 *   3. `dueForReclaim()` / `prune()`：满 30 天才允许物理删除，且必须显式命令
 */
class MediaRegistry
{
    /** 起租用到回收的默认间隔（天） */
    public const RECLAIM_DAYS = 30;

    /** 不纳入garhugo媒体库的目录：转账凭证属敏感单据，按用户分日隔离，不给后台浏览 */
    public const EXCLUDED_DIRS = ['uploads/vouchers', 'vouchers'];

    /**
     * 引用来源：单值列
     *
     * 统一走模型查询而非 DB 表，这样带 SoftDeletes 的表（products / users / sys_user）
     * 会**自动排除已软删的行** —— 已删除商品的图不该继续算作「在用」。
     *
     * @var array<int, array{model: class-string<Model>, column: string}>
     */
    private const STRING_SOURCES = [
        ['model' => Product::class, 'column' => 'main_image'],
        ['model' => ProductImage::class, 'column' => 'url'],
        ['model' => Brand::class, 'column' => 'logo'],
        ['model' => HomeBanner::class, 'column' => 'image'],
        ['model' => User::class, 'column' => 'avatar'],
        ['model' => SysUser::class, 'column' => 'avatar'],
        ['model' => CsFaqArticle::class, 'column' => 'cover_image'],
    ];

    /**
     * 引用来源：富文本 / 嵌套 JSON（正文内联图）
     *
     * @var array<int, array{model: class-string<Model>, column: string}>
     */
    private const TEXT_SOURCES = [
        ['model' => Product::class, 'column' => 'description'],
        ['model' => Product::class, 'column' => 'description_md'],
        ['model' => CsFaqArticle::class, 'column' => 'content'],
        ['model' => CsFaqArticle::class, 'column' => 'content_md'],
        ['model' => CsFaqArticle::class, 'column' => 'blocks'],
        ['model' => CsFaqArticle::class, 'column' => 'page_fields'],
    ];

    /**
     * 引用来源：图片路径数组（JSON 列）
     *
     * @var array<int, array{model: class-string<Model>, column: string}>
     */
    private const LIST_SOURCES = [
        ['model' => Review::class, 'column' => 'images'],
        ['model' => Refund::class, 'column' => 'images'],
        ['model' => Refund::class, 'column' => 'admin_images'],
        ['model' => CsTicketMessage::class, 'column' => 'images'],
    ];

    /** 存图片的系统配置键（system_configs 不是模型 rb_instance，单独扫） */
    private const CONFIG_SOURCES = ['site.logo', 'site.logo_small'];

    /**
     * 上传登记：md5 去重 + 元信息入库
     *
     * 命中既有 md5 时**不再写盘**，直接复用既有 path —— 重复素材零新增占用。
     *
     * @return array{path: string, url: string, reused: bool, media: MediaFile|null}
     */
    public function registerUpload(UploadedFile $file, string $module = 'common', ?int $uploadedBy = null): array
    {
        $hash = $this->hashOf($file->getRealPath());
        $existing = $hash !== null
            ? MediaFile::withTrashed()->where('md5', $hash)->first()
            : null;

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [
                'path' => $existing->path,
                'url' => Storage::disk($existing->disk)->url($existing->path),
                'reused' => true,
                'media' => $existing,
            ];
        }

        $path = $file->store('uploads/'.$module.'/'.now()->format('Ymd'), self::disk());

        $media = $this->record($path, [
            'module' => $module,
            'uploaded_by' => $uploadedBy,
            'original_name' => $file->getClientOriginalName(),
        ]);

        return [
            'path' => $path,
            'url' => Storage::disk(self::disk())->url($path),
            'reused' => false,
            'media' => $media,
        ];
    }

    /**
     * 登记磁盘上已存在的文件（存量回填 / `media:scan` 用）
     *
     * @return MediaFile|null null 表示该文件不需要登记（凭证目录 / 不可读）
     */
    public function registerExisting(string $path): ?MediaFile
    {
        $path = ltrim($path, '/');

        foreach (self::EXCLUDED_DIRS as $dir) {
            if (str_starts_with($path, $dir.'/')) {
                return null;
            }
        }

        if (! Storage::disk(self::disk())->exists($path)) {
            return null;
        }

        $existing = MediaFile::withTrashed()->where('path', $path)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->record($path, ['module' => $this->moduleOf($path)]);
    }

    /**
     * 写一条登记表（含宽高 / MIME / 体积等元信息）
     *
     * @param  array<string, mixed>  $extra
     */
    private function record(string $path, array $extra = []): ?MediaFile
    {
        $disk = self::disk();
        $absolute = Storage::disk($disk)->path($path);

        $size = @filesize($absolute) ?: 0;
        $mime = @mime_content_type($absolute) ?: null;
        [$width, $height] = $this->dimensions($absolute);

        try {
            return MediaFile::query()->create(array_merge([
                'disk' => $disk,
                'path' => $path,
                'md5' => $this->hashOf($absolute),
                'mime' => $mime,
                'size' => $size,
                'width' => $width,
                'height' => $height,
                'usage_count' => 0,
            ], $extra));
        } catch (Throwable $e) {
            // 登记失败绝不能阻断上传主流程 —— 只是少了索引，业务照常
            report($e);

            return null;
        }
    }

    /**
     * 替换文件内容但**保留原 path**（媒体库最有价值的操作）
     *
     * 换 banner 图 / 换商品主图时，业务表里存的那串相对路径一字不改，
     * 因此所有引用方自动生效 —— 不需要改任何业务表。
     *
     * ⚠️ 副作用提醒：同 md5 复用的记录共享同一个物理文件（P0 去重机制），
     * 替换会同时影响它们。这是「去重」的既有代价，接口层已在响应里带出 `reused_paths`。
     *
     * @return array{media: MediaFile, url: string, old_size: int, reused_paths: array<int, string>}
     *
     * @throws \RuntimeException 物理文件不可写时
     */
    public function replaceFile(MediaFile $media, UploadedFile $file): array
    {
        $disk = Storage::disk($media->disk);
        $oldSize = (int) $media->size;

        // 同 md5 的其它登记记录（去重复用者）
        $reusedPaths = $media->md5 !== null
            ? MediaFile::query()
                ->where('md5', $media->md5)
                ->where('path', '!=', $media->path)
                ->pluck('path')
                ->all()
            : [];

        $contents = (string) file_get_contents($file->getRealPath());
        if ($disk->put($media->path, $contents) === false) {
            throw new \RuntimeException('图片写入失败：'.$media->path);
        }

        $absolute = $disk->path($media->path);
        [$width, $height] = $this->dimensions($absolute);

        $media->update([
            'md5' => $this->hashOf($absolute),
            'mime' => @mime_content_type($absolute) ?: $media->mime,
            'size' => @filesize($absolute) ?: 0,
            'width' => $width,
            'height' => $height,
            'original_name' => $file->getClientOriginalName() ?: $media->original_name,
        ]);

        return [
            'media' => $media->fresh() ?? $media,
            'url' => $disk->url($media->path),
            'old_size' => $oldSize,
            'reused_paths' => $reusedPaths,
        ];
    }

    /**
     * 重算引用计数
     *
     * @param  array<int, string>|null  $paths 指定 path 时只算这些（删除联动的局部重算）；null = 全量
     * @return array<string, int> path => usage_count
     */
    public function recomputeUsage(?array $paths = null): array
    {
        $counts = [];
        foreach ($paths ?? [] as $path) {
            $counts[$path] = 0;
        }

        foreach ($this->sources() as $source) {
            $query = $source['model']::query()->select([$source['column']]);

            if ($paths !== null && $paths !== []) {
                $query->where(function (Builder $q) use ($source, $paths): void {
                    foreach ($paths as $path) {
                        $q->orWhere($source['column'], 'like', '%'.$path.'%');
                    }
                });
            }

            foreach ($query->cursor() as $row) {
                $raw = MediaUrl::normalizeRaw($this->rawValue($row, $source['column']));

                foreach (MediaUrl::extractPaths($raw) as $found) {
                    if ($paths !== null && ! isset($counts[$found])) {
                        continue;
                    }
                    $counts[$found] = ($counts[$found] ?? 0) + 1;
                }
            }
        }

        foreach ($this->configPaths() as $found) {
            $counts[$found] = ($counts[$found] ?? 0) + 1;
        }

        $this->persist($counts, $paths === null);

        return $counts;
    }

    /**
     * 把计数写回登记表
     *
     * 全量扫描时才允许把「没被扫到的记录」清零（`$resetMissing`）；
     * 局部重算只更新自己那几条，避免误伤并发写入中的记录。
     *
     * @param  array<string, int>  $counts
     */
    private function persist(array $counts, bool $resetMissing): void
    {
        $now = now();

        foreach ($counts as $path => $count) {
            $media = MediaFile::withTrashed()->where('path', $path)->first();
            if ($media === null) {
                continue;
            }

            // 「又被引用了」自动回到在用状态：32 回收窗口内的误判在这里自愈
            if ($media->trashed() && $count > 0) {
                $media->restore();
            }

            $media->forceFill([
                'usage_count' => $count,
                'last_scanned_at' => $now,
            ])->save();
        }

        if ($resetMissing) {
            MediaFile::query()
                ->whereNotIn('path', array_keys($counts) ?: [''])
                ->update(['usage_count' => 0, 'last_scanned_at' => $now]);
        }
    }

    /**
     * 删除联动：业务实体删除后，对它引用过的图片重算并按需软删
     *
     * @param  array<int, string>|null  $paths
     * @return array<int, string> 本次进入回收窗口（已软删）的 path
     */
    public function releaseReferences(?array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            array_map(fn ($p) => is_string($p) ? MediaUrl::toPath($p) : null, $paths ?? []),
        )));

        if ($paths === []) {
            return [];
        }

        $counts = $this->recomputeUsage($paths);
        $reclaimed = [];

        foreach ($counts as $path => $count) {
            if ($count > 0) {
                continue;
            }
            if ($this->softDeleteByPath($path)) {
                $reclaimed[] = $path;
            }
        }

        return $reclaimed;
    }

    /** 引用归零 → 软删进入回收窗口 */
    public function softDeleteByPath(string $path): bool
    {
        $media = MediaFile::query()->where('path', $path)->first();

        if ($media === null) {
            return false;
        }

        return (bool) $media->delete();
    }

    /**
     * 全量回收：把当前引用为 0 的在册记录软删
     *
     * @return array<int, string>
     */
    public function reclaimUnused(): array
    {
        return $this->releaseReferences(
            MediaFile::query()->pluck('path')->all(),
        );
    }

    /**
     * 到期清单：已软删满 $days 天、且仍无引用的记录
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MediaFile>
     */
    public function dueForReclaim(int $days = self::RECLAIM_DAYS)
    {
        return MediaFile::onlyTrashed()
            ->where('usage_count', 0)
            ->where('deleted_at', '<=', now()->subDays($days))
            ->orderByDesc('deleted_at')
            ->get();
    }

    /**
     * 物理删除到期文件
     *
     * ⚠️ 会真删盘上的文件，且不可恢复 —— 只允许被显式命令调用，且默认必须先 dry-run。
     *
     * @return int 实际删除的记录数
     */
    public function prune(int $days = self::RECLAIM_DAYS, bool $dryRun = false, ?callable $onDelete = null): int
    {
        $count = 0;

        foreach ($this->dueForReclaim($days) as $media) {
            // 双保险：真删前再核一次「无引用且文件存在」
            if ((int) $media->usage_count !== 0) {
                continue;
            }

            $onDelete !== null && $onDelete($media, $dryRun);

            if ($dryRun) {
                $count++;

                continue;
            }

            try {
                Storage::disk($media->disk)->delete($media->path);
                $media->forceDelete();
                $count++;
            } catch (Throwable $e) {
                Log::warning('媒体回收失败：'.$media->path, ['error' => $e->getMessage()]);
            }
        }

        return $count;
    }

    /** 磁盘上全部可登记文件的相对路径（回填用） */
    public function diskFiles(): array
    {
        $files = [];

        foreach (Storage::disk(self::disk())->allFiles('uploads') as $file) {
            $files[] = $file;
        }

        return $files;
    }

    /** @return array<int, string> */
    public function configPaths(): array
    {
        return SystemConfig::query()
            ->whereIn('config_key', self::CONFIG_SOURCES)
            ->pluck('config_value')
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->flatMap(fn ($v) => MediaUrl::extractPaths($v))
            ->unique()
            ->values()
            ->all();
    }

    /** 全部引用来源（单值 / 富文本 / JSON 数组） */
    private function sources(): array
    {
        return [...self::STRING_SOURCES, ...self::LIST_SOURCES, ...self::TEXT_SOURCES];
    }

    /**
     * 取列的**原始库值**：绕开 cast，因为 cast 会把 '/storage/x' 变成绝对 URL，
     * 而扫描需要的是能与 path 做 LIKE 匹配的相对形态。
     */
    private function rawValue(Model $row, string $column): mixed
    {
        if (array_key_exists($column, $row->getAttributes())) {
            return $row->getAttributes()[$column];
        }

        return $row->getAttribute($column);
    }

    /** @return array{int|null, int|null} */
    private function dimensions(string $absolute): array
    {
        $info = @getimagesize($absolute);

        if ($info === false || ! isset($info[0], $info[1])) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    private function hashOf(?string $absolute): ?string
    {
        if ($absolute === null || ! is_file($absolute)) {
            return null;
        }

        $hash = @md5_file($absolute);

        return $hash === false ? null : $hash;
    }

    /** 从路径反推模块：uploads/products/20260918/x.png → products */
    private function moduleOf(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        return $segments[1] ?? 'common';
    }

    public static function disk(): string
    {
        return MediaUrl::DISK;
    }
}
