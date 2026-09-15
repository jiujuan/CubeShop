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
}
