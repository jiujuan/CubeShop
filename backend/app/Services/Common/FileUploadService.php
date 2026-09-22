<?php

namespace App\Services\Common;

use App\Exceptions\BusinessException;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * 文件上传服务（架构文档 4.1.1）
 *
 * 图片存在 storage/app/public/uploads，**库里一律存相对路径**（`uploads/products/20260918/x.png`），
 * URL 在读取时才由 {@see MediaUrl::to()} 拼出 —— 换域名 / 上 CDN / 切 OSS 都零数据迁移。
 *
 * 上传同时登记到 `media_files`（md5 去重 + 元信息），详见 {@see MediaRegistry}。
 *
 * ⚠️ `uploadImage()` 返回的是**绝对 URL**（对前端契约不变）。要写库的那份相对路径用
 * {@see self::uploadImagePath()} —— 不过多数场景把返回值直接交给模型即可，
 * MediaPath cast 会自动归一成相对路径。
 */
class FileUploadService
{
    private const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const MAX_SIZE_KB = 5120;

    public function __construct(private readonly MediaRegistry $registry) {}

    /**
     * 上传图片，返回可访问 URL
     *
     * @throws BusinessException
     */
    public function uploadImage(UploadedFile $file, string $module = 'common', ?int $uploadedBy = null): string
    {
        $this->assertValidImage($file, self::MAX_SIZE_KB, self::ALLOWED_MIMES);

        return $this->registry->registerUpload($file, $module, $uploadedBy)['url'];
    }

    /**
     * 上传图片，返回库里该存的相对路径
     *
     * @throws BusinessException
     */
    public function uploadImagePath(UploadedFile $file, string $module = 'common', ?int $uploadedBy = null): string
    {
        $this->assertValidImage($file, self::MAX_SIZE_KB, self::ALLOWED_MIMES);

        return $this->registry->registerUpload($file, $module, $uploadedBy)['path'];
    }

    /**
     * 上传线下转账凭证（§5.4）：仅前台、登录用户、按用户分日存储
     *
     * 限制：jpg/jpeg/png/webp，≤ 3MB，单用户单日 ≤ 20 张。
     *
     * ⚠️ 凭证**不登记进媒体库**：它是敏感单据，后台不应可浏览；这里保持原有「校验 + 落盘」的裸逻辑。
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

    /**
     * 通用图片校验（有效性 / 体积 / 扩展名白名单）
     *
     * @param  array<int, string>  $extensions
     *
     * @throws BusinessException
     */
    private function assertValidImage(UploadedFile $file, int $maxKb, array $extensions): void
    {
        if (! $file->isValid()) {
            throw BusinessException::badRequest('上传文件无效');
        }

        if ($file->getSize() > $maxKb * 1024) {
            throw BusinessException::badRequest('文件大小超过 '.intdiv($maxKb, 1024).'MB 限制');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $extensions, true)) {
            throw BusinessException::badRequest('仅支持图片格式：'.implode('/', $extensions));
        }
    }
}
