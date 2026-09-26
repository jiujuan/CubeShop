<?php

namespace App\Http\Controllers;

use App\Services\Member\CheckinService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前台签到（会员成长计划 S2）
 *
 * - GET  /checkin  签到状态（连续天数 / 今日可得 / 明日可得 / 可用积分）
 * - POST /checkin  签到（同一天只能一次，重复返回 40009 / HTTP 409）
 *
 * 奖励算法与积分发放都在 {@see CheckinService}，这里只做参数与响应形态的适配。
 */
class CheckinController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CheckinService $checkins)
    {
    }

    /** 签到状态：GET /checkin */
    public function status(Request $request): JsonResponse
    {
        return $this->success($this->checkins->status($request->user()->id));
    }

    /**
     * 签到：POST /checkin
     *
     * 返回签到后的完整状态 + 本次实发积分，前端可直接用它刷新卡片，无需再拉一次状态。
     * 重复签到由服务抛 40009（HTTP 409）；签到未开启抛 40000（HTTP 400）。
     */
    public function store(Request $request): JsonResponse
    {
        $row = $this->checkins->checkin($request->user()->id);

        return $this->success([
            'points' => (int) $row->points,
            'streak' => (int) $row->streak,
            'date' => $row->checkin_date->toDateString(),
        ] + $this->checkins->status($request->user()->id));
    }
}
