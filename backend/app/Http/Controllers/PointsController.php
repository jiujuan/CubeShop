<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserPointLog;
use App\Services\Member\PointsService;
use App\Services\Member\PointsSettings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 买家端积分（会员成长计划 S3）
 *
 * 设计文档：docs/design/points-checkin-membership.md §9 / §16 S3
 *
 * 消费返积分（S3）让买家在支付成功后获得积分，本控制器把「我的积分」对买家自己开放：
 * - GET /user/points      概览（开关 / 名称 / 账户 / 最近流水）
 * - GET /user/points/logs 流水（分页）
 *
 * 与后台 UserPointController 的区别：这里只看当前登录买家自己，无 member.view/manage 权限门槛。
 */
class PointsController extends Controller
{
    use ApiResponse;

    /** 概览里附带的最近流水条数 */
    private const LOG_LIMIT = 10;

    public function __construct(
        private readonly PointsService $points,
        private readonly PointsSettings $settings,
    ) {
    }

    /**
     * 我的积分概览
     * GET /user/points
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return $this->success([
            'enabled' => $this->settings->enabled(),
            'name' => $this->settings->name(),
            'account' => $this->points->summary($userId),
            'logs' => $this->points->logs($userId, self::LOG_LIMIT)->map(fn (UserPointLog $log) => $this->logRow($log))->all(),
        ]);
    }

    /**
     * 我的积分流水（分页）
     * GET /user/points/logs?per_page=20
     */
    public function logs(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $perPage = min((int) $request->query('per_page', 20), 50);

        $page = UserPointLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->success([
            'list' => collect($page->items())->map(fn (UserPointLog $log) => $this->logRow($log))->all(),
            'pagination' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** 流水行结构（与后台对齐，前端可复用渲染） */
    private function logRow(UserPointLog $log): array
    {
        return [
            'id' => $log->id,
            'type' => $log->type,
            'type_label' => $log->type_label,
            'points' => $log->points,
            'frozen_points' => $log->frozen_points,
            'balance_before' => $log->balance_before,
            'balance_after' => $log->balance_after,
            'remark' => $log->remark,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
