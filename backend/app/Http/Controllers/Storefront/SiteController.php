<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Common\ConfigService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 前台站点信息（P-SiteConfig）
 *
 * 公开接口：前端启动时拉取站点名称与大小 logo，用于顶栏/登录页/页脚/文档标题。
 * 读取走 `ConfigService`（带缓存，后台保存配置时会 flush，故改完刷新即生效）。
 */
class SiteController extends Controller
{
    use ApiResponse;

    /** 未配置时的兜底站点名（与前端默认值保持一致） */
    private const DEFAULT_NAME = 'CubeShop';

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    /**
     * 站点基础信息 GET /site/config（公开，无需登录）
     *
     * logo 与 logo_small 未配置时返回空串，由前端回落到内置图标。
     */
    public function show(): JsonResponse
    {
        return $this->success([
            'name' => $this->config->get('site.name') ?: self::DEFAULT_NAME,
            'logo' => $this->config->get('site.logo', '') ?? '',
            'logo_small' => $this->config->get('site.logo_small', '') ?? '',
        ]);
    }
}
