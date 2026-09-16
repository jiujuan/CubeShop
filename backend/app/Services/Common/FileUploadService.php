<?php

namespace App\Services\Common;

use App\Exceptions\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * 文件上传服务（架构文档 4.1.1）
 * V1.0 本地存储占位：图片存 storage/app/public/uploads，返回 /storage 相对 URL，
 * 后续可平滑替换为 OSS/S3（Storage 驱动切换）。
 */
class FileUploadService
{
    private const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const MAX_SIZE_KB = 5120;

    /**
     * 上传图片，返回可访问 URL
     *
     * @throws BusinessException
     */
    public function uploadImage(UploadedFile $file, string $module = 'common'): string
    {
        if (! $file->isValid()) {
            throw BusinessException::badRequest('上传文件无效');
        }

        if ($file->getSize() > self::MAX_SIZE_KB * 1024) {
            throw BusinessException::badRequest('文件大小超过 5MB 限制');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_MIMES, true)) {
            throw BusinessException::badRequest('仅支持图片格式：'.implode('/', self::ALLOWED_MIMES));
        }

        $path = $file->store("uploads/{$module}/".now()->format('Ymd'), 'public');

        return Storage::disk('public')->url($path);
    }

    /**
     * 上传线下转账凭证（§5.4）：仅前台、登录用户、按用户分日存储
     *
     * 限制：jpg/jpeg/png/webp，≤ 3MB，单用户单日 ≤ 20 张。
     *
     * @throws BusinessException
     */
    public function uploadVoucher(UploadedFile $file, int $userId): string
    {
        if (! $file->isValid()) {
            throw BusinessException::badRequest('上传文件无效');
        }

        if ($file->getSize() > 3072 * 1024) {
            throw BusinessException::badRequest('凭证图片大小超过 3MB 限制');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw BusinessException::badRequest('凭证仅支持 jpg/jpeg/png/webp 格式');
        }

        $today = now()->format('Ymd');
        $dir = "vouchers/{$userId}/{$today}";

        // 单用户单日上限 20 张（按当日目录文件数判定的轻量限流）
        if (count(Storage::disk('public')->files($dir)) >= 20) {
            throw BusinessException::badRequest('今日上传凭证数量已达上限（20 张）');
        }

        $path = $file->store($dir, 'public');

        return Storage::disk('public')->url($path);
    }
}
