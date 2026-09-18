<?php

namespace App\Http\Controllers;

use App\Models\HomeBanner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 用户端首页广告位（P-HomeBanner，公开无需登录）
 *
 * GET /api/banners —— 一次性返回三个位置的广告数据（按 position 分组、sort_order 升序），
 * 仅含 is_enabled=true 的记录。数据量为运营配置级（个位数~十位数），不做分页。
 */
class HomeBannerController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rows = HomeBanner::query()
            ->enabled()
            ->ordered()
            ->get(['position', 'public_id', 'image', 'title', 'subtitle', 'link_url']);

        $grouped = [
            HomeBanner::POSITION_BANNER => [],
            HomeBanner::POSITION_PROMO => [],
            HomeBanner::POSITION_BOTTOM => [],
        ];

        foreach ($rows as $row) {
            if (! array_key_exists($row->position, $grouped)) {
                continue;
            }
            $grouped[$row->position][] = [
                'id' => $row->public_id,
                'image' => $row->image,
                'title' => $row->title,
                'subtitle' => $row->subtitle,
                'link_url' => $row->link_url,
            ];
        }

        return $this->success(['banners' => $grouped]);
    }
}
