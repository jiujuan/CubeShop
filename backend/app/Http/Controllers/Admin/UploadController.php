<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Common\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 文件上传（API 文档 12.5：POST /admin/upload）
 * 权限：product.create / product.update / category.manage 均可传图
 */
class UploadController extends Controller
{
    use ApiResponse;

    public function __construct(private FileUploadService $uploader)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $url = $this->uploader->uploadImage($data['file'], 'products');

        return $this->success([
            'url' => $url,
        ], '上传成功');
    }
}
