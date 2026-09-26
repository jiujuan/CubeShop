<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Common\OperationLogService;
use App\Services\Member\CheckinService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台签到管理（会员成长计划 S2 / D8）
 *
 * 目前只有一个动作：**补签**（用户漏签后由运营补上某一天）。
 * 权限挂 `member.manage` —— 补签会真实发放积分，与人工调整积分同级。
 *
 * 补签只补「历史某一天没签到」的空洞，不追溯补发后续日期的积分差额
 * （详见 {@see CheckinService::backfill()} 的套利说明）。
 */
class UserCheckinController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CheckinService $checkins,
        private readonly OperationLogService $operationLog,
    ) {
    }

    /**
     * 补签：POST /admin/users/{id}/checkins/backfill body: {date}
     *
     * ⚠️ 日期必填且必须是 `Y-m-d`，不能是今天或未来（今天该走前台签到）。
     */
    public function backfill(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before:today'],
        ]);

        $user = User::query()->find($id);

        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        $row = $this->checkins->backfill((int) $user->id, $data['date'], (int) $request->user()->id);

        $this->operationLog->record(
            $request->user()->id,
            'member',
            'backfill_checkin',
            'user_checkins',
            (int) $row->id,
            sprintf(
                '为用户 %s（#%d）补签 %s：连续第 %d 天，发放 %d 积分',
                $user->username,
                $user->id,
                $data['date'],
                $row->streak,
                $row->points,
            ),
        );

        return $this->success([
            'date' => $row->checkin_date->toDateString(),
            'streak' => (int) $row->streak,
            'points' => (int) $row->points,
            'is_backfill' => true,
        ]);
    }
}
