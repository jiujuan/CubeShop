<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\NavService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 前台顶部导航 GET /api/nav（公开，无需登录）
 *
 * 后台在「导航管理」里编排条目（商品分类引用 / 自定义链接，位置由 sort 决定），
 * 这里返回**展开后的最终渲染列表**，前端拿到即渲染，不做合并。
 */
class NavController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NavService $nav)
    {
    }

    public function index(): JsonResponse
    {
        return $this->success($this->nav->items());
    }
}
