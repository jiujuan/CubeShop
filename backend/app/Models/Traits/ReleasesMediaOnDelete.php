<?php

namespace App\Models\Traits;

use App\Casts\MediaNested;
use App\Casts\MediaPath;
use App\Casts\MediaPathList;
use App\Casts\MediaRichText;
use App\Services\Common\MediaRegistry;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * 图片引用的生命周期联动（媒体治理 P0，设计文档 §5）
 *
 * 解决「删除零联动」：业务实体被删 / 换新图之后，**只解除引用计数，不直接删文件** ——
 * 引用归零的图由 {@see MediaRegistry::releaseReferences()} 软删，进入 30 天回收窗口，
 * 满窗后才轮到 `media:prune` 决定是否真删。
 *
 * 三条保守约定（都是为了「绝不误删」）：
 * 1. 解除引用 ≠ 删文件：只做软删并计入 30 天回收窗口，物理删除永远由显式命令负责；
 * 2. 任何异常只上报不抛出 —— 清理是旁路工作，不许反过来搞挂主流程；
 * 3. 凭媒体库**重新扫描**得到的 usage_count 说话，不信删除瞬间的现场推断。
 *    （实测踩坑：Eloquent 在 `updated` 前后对 `getOriginal()` 的同步时机不一致，
 *     「这块字段刚才是什么图」不能靠 `$original` 猜，必须实际查表。）
 *
 * ⚠️ 引用列清单取自模型 `$casts`，与 {@see MediaPath} 系列保持唯一真源：
 * 给哪列加了媒体 cast，它就自动被纳入联动，不需要再改第二处。
 */
trait ReleasesMediaOnDelete
{
    /** 媒体 cast 类：凡是列上挂了这些 cast，就视为图片引用列 */
    private const MEDIA_CASTS = [
        MediaPath::class,
        MediaPathList::class,
        MediaRichText::class,
        MediaNested::class,
    ];

    /**
     * 更新前的引用快照
     *
     * ⚠️ 必须在 `updating` 里采：Eloquent 在 `updated` 之前就调用了 `syncOriginal()`，
     * 彼时 `getOriginal()` 已经变成新值，取不到「换掉的旧图」。
     *
     * @var array<int, string>
     */
    private array $mediaPathsBeforeUpdate = [];

    public static function bootReleasesMediaOnDelete(): void
    {
        // 实体删除（含软删）：解除它引用过的图片
        static::deleted(function (Model $model): void {
            static::releaseLater($model->referencedMediaPaths());
        });

        static::updating(function (Model $model): void {
            $model->mediaPathsBeforeUpdate = $model->originalReferencedMediaPaths();
        });

        // 换图：旧图不再被引用，同样解除（解决「换图不回收」）
        static::updated(function (Model $model): void {
            $orphaned = array_diff($model->mediaPathsBeforeUpdate, $model->referencedMediaPaths());

            if ($orphaned === []) {
                return;
            }

            static::releaseLater(array_values($orphaned));
        });
    }

    /**
     * 该实体当前引用的全部图片路径
     *
     * 默认由媒体 cast 的列推导；需要额外来源（如商品相册子表）的模型自行 override。
     *
     * @return array<int, string>
     */
    public function referencedMediaPaths(): array
    {
        return $this->mediaColumnPaths();
    }

    /** @return array<int, string> */
    public function mediaColumnPaths(): array
    {
        return $this->collectPaths(false);
    }

    /**
     * 读取媒体列的原始值并抽成路径列表
     *
     * @param  bool  $preferOriginal true = 取库里现值（更新前的旧图）；false = 取当前待写入的值
     * @return array<int, string>
     */
    private function collectPaths(bool $preferOriginal): array
    {
        $paths = [];
        $attributes = $this->getAttributes();

        foreach ($this->mediaColumns() as $column) {
            $raw = $preferOriginal
                ? ($this->getRawOriginal($column) ?? ($attributes[$column] ?? null))
                : (array_key_exists($column, $attributes) ? $attributes[$column] : $this->getRawOriginal($column));

            foreach (MediaUrl::extractPaths(self::normalizeRaw($raw)) as $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /** @return array<int, string> 取更新**前**（库里现值）的引用，供换图比对用 */
    public function originalReferencedMediaPaths(): array
    {
        return $this->collectPaths(true);
    }

    /** @return array<int, string> 本模型上的媒体列（来自 $casts） */
    public function mediaColumns(): array
    {
        return collect($this->getCasts())
            ->filter(fn (string $cast): bool => in_array($cast, self::MEDIA_CASTS, true))
            ->keys()
            ->all();
    }

    /**
     * @param  array<int, string>  $paths
     */
    private static function releaseLater(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            app(MediaRegistry::class)->releaseReferences($paths);
        } catch (Throwable $e) {
            // 旁路清理失败不得影响业务主流程
            report($e);
        }
    }

    /**
     * 原始库值 → 可被 {@see MediaUrl::extractPaths()} 消化的形态
     *
     * JSON 列的原始值是字符串（"[\"uploads\\/x.png\"]"），先解成数组再交给抽取器，
     * 否则整串 JSON 会被当成"一个路径"。
     */
    private static function normalizeRaw(mixed $raw): array|string|null
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $trim = trim($raw);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($trim, true);

            return is_array($decoded) ? $decoded : $trim;
        }

        return $raw;
    }
}
