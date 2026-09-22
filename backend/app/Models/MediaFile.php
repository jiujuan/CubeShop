<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 图片资产登记表（媒体治理 P0）
 *
 * 旁路索引：业务表不引用本表 id，两边以 `path` 关联；本表只负责「知道这张图的一切」
 * （文件元信息、引用计数、回收状态）。详见 docs/design/CubeShop_Media_Library_v1.0.md §3。
 *
 * ⚠️ `usage_count` 由 {@see \App\Services\Common\MediaRegistry} 扫描得出，**不可实时信任**；
 * 基于它的回收必须保守 —— 先软删进 30 天窗口，到期后人工/显式命令才物理删除。
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string|null $md5
 * @property string|null $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $original_name
 * @property string $module
 * @property int|null $uploaded_by
 * @property int $usage_count
 * @property \Carbon\CarbonInterface|null $last_scanned_at
 * @property \Carbon\CarbonInterface|null $deleted_at
 */
class MediaFile extends Model
{
    use SoftDeletes;

    protected $table = 'media_files';

    protected $fillable = [
        'disk', 'path', 'md5', 'mime', 'size', 'width', 'height',
        'original_name', 'module', 'uploaded_by', 'usage_count', 'last_scanned_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'uploaded_by' => 'integer',
        'usage_count' => 'integer',
        'last_scanned_at' => 'datetime',
    ];

    /** 回收窗口默认天数：软删满 30 天才允许物理删除 */
    public const RECLAIM_DAYS = 30;

    /** 该记录的物理文件在磁盘上是否仍然存在 */
    public function existsOnDisk(): bool
    {
        return \Illuminate\Support\Facades\Storage::disk($this->disk)->exists($this->path);
    }

    /** 推荐的字符串形态（后台列表 / 日志里展示用） */
    public function displayName(): string
    {
        return $this->original_name ?: basename($this->path);
    }
}
