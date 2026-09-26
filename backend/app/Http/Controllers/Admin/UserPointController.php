<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPointLog;
use App\Services\Common\OperationLogService;
use App\Services\Member\PointsService;
use App\Support\ApiResponse;
use App\Support\Member\PointsRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台会员积分（会员成长计划 S1）
 *
 * 权限两档：
 * - member.view：查看积分账户与流水
 * - member.manage：人工调整积分（动用户资产，原因必填 + 操作日志 + 二次确认由前端承担）
 *
 * 挂在 /admin/users/{id} 之下，与后台地址接口（/users/{userId}/addresses）同一组织方式：
 * 积分是**用户维度**的附属资产，不做成独立的顶层资源。
 */
class UserPointController extends Controller
{
    use ApiResponse;

    /** 详情里附带的流水条数 */
    private const LOG_LIMIT = 10;

    public function __construct(
        private readonly PointsService $points,
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 用户积分账户与最近流水
     * GET /admin/users/{id}/points
     */
    public function show(int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        return $this->success([
            'user_id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'account' => $this->points->summary($user->id),
            'logs' => $this->points->logs($user->id, self::LOG_LIMIT)->map(fn (UserPointLog $log) => $this->logRow($log))->all(),
        ]);
    }

    /**
     * 人工调整积分（正=加分，负=减分）
     * POST /admin/users/{id}/points/adjust  body: { points, reason }
     */
    public function adjust(Request $request, int $id): JsonResponse
    {
        $max = PointsRules::ADJUST_MAX;

        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', "min:-{$max}", "max:{$max}"],
            'reason' => ['required', 'string', 'max:100'],
        ], [
            'points.required' => '请输入调整积分',
            'points.integer' => '积分必须为整数',
            'points.not_in' => '调整积分不能为 0',
            'points.min' => "单次调整不得超过 {$max} 积分",
            'points.max' => "单次调整不得超过 {$max} 积分",
            'reason.required' => '请填写调整原因',
            'reason.max' => '调整原因不能超过 100 字',
        ]);

        $user = User::query()->find($id);

        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        $log = $this->points->adjust($user->id, (int) $data['points'], (string) $data['reason'], $request->user()->id);

        $this->operationLog->record(
            $request->user()->id,
            'member',
            'adjust_points',
            'users',
            $user->id,
            sprintf(
                '%s用户 %s（#%d）%d 积分，原因：%s，调整后可用 %d',
                $data['points'] > 0 ? '增加' : '扣减',
                $user->username,
                $user->id,
                abs((int) $data['points']),
                $data['reason'],
                $log->balance_after,
            ),
        );

        return $this->success([
            'account' => $this->points->summary($user->id),
            'log' => $this->logRow($log),
        ], $data['points'] > 0 ? '积分已增加' : '积分已扣减');
    }

    /** 流水行结构（后台表格直接用） */
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
