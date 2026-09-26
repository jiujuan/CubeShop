<?php

namespace App\Services\Member;

use App\Exceptions\BusinessException;
use App\Models\UserCheckin;
use App\Support\Member\PointsRules;
use App\Support\Member\SigninReward;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 签到服务（会员成长计划 S2）
 *
 * 设计文档：docs/design/points-checkin-membership.md §7
 *
 * ### 三件事，只有第一件是「算」
 *
 * 1. 算连续天数与奖励（委托 {@see SigninReward}，纯函数）
 * 2. 防重复（unique(user_id, checkin_date) 兜底 + 前置查询给友好提示）
 * 3. 发积分（委托 {@see PointsService}，这是积分的唯一写入口）
 *
 * ### 连续天数为什么落库
 *
 * 实时统计「最近 N 天有几条记录」在补签时会算错：补了昨天之后，
 * 今天到底算连续几天，取决于补的那天是否与原序列衔接 —— 查表数不出来。
 * 落库后每个日期的天数是确定值，补签只需推进它之后的链。
 *
 * ### 有效连续天数（status.streak）与「上次签到天数」不是一回事
 *
 * 最后一次签到在昨天或今天 → 连续链还活着，streak = 那天的天数；
 * 再往前（断签）→ 链已断，streak = 0，下次签到从 1 重新开始。
 * 前端展示「已连续 X 天」必须用前者，否则断签三天仍会显示「连续 5 天」。
 */
class CheckinService
{
    public function __construct(
        private readonly PointsService $points,
        private readonly PointsSettings $settings,
    ) {
    }

    /**
     * 签到状态（前台展示用，只读）
     *
     * @return array{
     *     date: string, checked: bool, streak: int, today_points: int,
     *     next_points: int, total_days: int, balance: int, milestone_days: list<int>,
     *     available: bool
     * }
     */
    public function status(int $userId, ?Carbon $today = null): array
    {
        $today = $this->normalizeDate($today);
        $config = $this->settings->signinConfig();
        $date = $today->toDateString();

        $todayRow = $this->findRow($userId, $date);
        $last = $this->lastBefore($userId, $date);

        // 已连续天数：今日已签 → 今日的天数；否则看链是否还活着（昨天签过才算活着）
        $streak = $this->effectiveStreak($last, $today);

        return [
            'date' => $date,
            'checked' => $todayRow !== null,
            'streak' => $streak,
            // 今日可得（未签）/ 今日实发（已签）
            'today_points' => $todayRow
                ? (int) $todayRow->points
                : SigninReward::pointsFor(SigninReward::nextStreak($streak), $config),
            // 明日可得：按「若明天接着签」的天数预演（7 日循环到头就回到第一档）
            'next_points' => SigninReward::pointsFor(
                SigninReward::nextStreak($todayRow ? (int) $todayRow->streak : SigninReward::nextStreak($streak)),
                $config,
            ),
            'total_days' => $this->totalDays($userId),
            'balance' => $this->points->balance($userId),
            'milestone_days' => SigninReward::milestoneDays($config['bonus']),
            'available' => $this->settings->signinAvailable(),
        ];
    }

    /**
     * 签到（同一天只能一次）
     *
     * @throws BusinessException 40009 当日已签到 / 40000 签到未开启
     */
    public function checkin(int $userId, ?Carbon $today = null): UserCheckin
    {
        return $this->sign($userId, $this->normalizeDate($today), false, null);
    }

    /**
     * 后台补签（S2 / D8）
     *
     * 与前台签到的区别：可指定历史日期，`is_backfill=1` 且记录操作人。
     *
     * ⚠️ **积分只按补签当日算出的天数发放，不追溯补发后续日期的差额**：
     *    用户昨天漏签（本该第 7 天拿里程碑）今天才补，不会因为补签就倒找他 50 分 ——
     *    否则事后补签会变成一种「等漏签再补」的套利路径。补签后连续链会重算，
     *    之后每天按新的天数正常发。
     *
     * @throws BusinessException 40000 日期非法 / 40009 该日已签到
     */
    public function backfill(int $userId, string $date, int $operatorId): UserCheckin
    {
        $target = $this->parseDate($date);
        $today = Carbon::today();

        if ($target->greaterThan($today)) {
            throw BusinessException::badRequest('补签日期不能晚于今天');
        }

        return $this->sign($userId, $target, true, $operatorId);
    }

    /** 最近签到记录（倒序） */
    public function recent(int $userId, int $limit = 10): array
    {
        return UserCheckin::query()
            ->where('user_id', $userId)
            ->orderByDesc('checkin_date')
            ->limit($limit)
            ->get()
            ->map(fn (UserCheckin $row) => [
                'date' => $row->checkin_date->toDateString(),
                'streak' => (int) $row->streak,
                'points' => (int) $row->points,
                'is_backfill' => (bool) $row->is_backfill,
            ])
            ->all();
    }

    /**
     * 签到主流程：算天数 → 落记录 → 发积分
     *
     * 三步在同一事务内：任何一步失败（含积分不足/规则冲突）都不会留下脏记录。
     */
    private function sign(int $userId, Carbon $date, bool $backfill, ?int $operatorId): UserCheckin
    {
        if (! $this->settings->signinAvailable()) {
            throw BusinessException::badRequest('签到功能未开启');
        }

        $config = $this->settings->signinConfig();
        $dateString = $date->toDateString();

        return DB::transaction(function () use ($userId, $date, $dateString, $backfill, $operatorId, $config) {
            if ($this->findRow($userId, $dateString) !== null) {
                throw BusinessException::conflict('该日期已签到');
            }

            $previous = $this->lastBefore($userId, $dateString);

            // 连续判定：前一天签过就接着数，否则从 1 重新开始（7 日循环见 nextStreak）
            $streak = $previous !== null && $this->isYesterday($previous->checkin_date, $date)
                ? SigninReward::nextStreak((int) $previous->streak)
                : 1;

            $points = SigninReward::pointsFor($streak, $config);

            try {
                $row = UserCheckin::query()->create([
                    'user_id' => $userId,
                    'checkin_date' => $dateString,
                    'streak' => $streak,
                    'points' => $points,
                    'is_backfill' => $backfill,
                    'created_by' => $operatorId,
                ]);
            } catch (QueryException) {
                // 并发护栏：unique(user_id, checkin_date) 兜住「同时点了两次」，
                // 前置查询只能给出友好提示，真正的正确性靠这层。
                throw BusinessException::conflict('该日期已签到');
            }

            if ($points > 0) {
                $this->points->credit(
                    $userId,
                    $points,
                    PointsRules::TYPE_SIGNIN,
                    'user_checkin',
                    (int) $row->id,
                    PointsRules::bizKey('checkin', $userId, $dateString),
                    $backfill ? '后台补签奖励' : '签到奖励',
                    $operatorId,
                );
            }

            // 补签可能改变后续日期的连续链（补了昨天，今天就不该还是 1），此处推进重算
            if ($backfill) {
                $this->resyncStreaksAfter($userId, $dateString);
            }

            return $row->fresh();
        });
    }

    /**
     * 重算某日期之后的连续天数链（仅 streak，不动已发积分）
     *
     * 补签插入历史节点后，其后的每一天都要按新链重新编号，否则会出现
     * 「9/26 补签成第 2 天，9/27 却还写着第 1 天」的断链展示。
     *
     * ⚠️ 只改 streak 不改 points：积分是既成事实，追溯补发会变成套利路径（见 backfill 注释）。
     */
    private function resyncStreaksAfter(int $userId, string $dateString): void
    {
        $rows = UserCheckin::query()
            ->where('user_id', $userId)
            ->where('checkin_date', '>', $dateString)
            ->orderBy('checkin_date')
            ->get();

        $previousDate = $dateString;
        $previous = $this->findRow($userId, $dateString);
        $streak = $previous !== null ? (int) $previous->streak : 0;

        foreach ($rows as $row) {
            $current = $row->checkin_date;

            $streak = $this->isYesterdayFor($previousDate, $current)
                ? SigninReward::nextStreak($streak)
                : 1;

            if ((int) $row->streak !== $streak) {
                $row->streak = $streak;
                $row->save();
            }

            $previousDate = $current->toDateString();
        }
    }

    /** 该日期是否已签到 */
    private function findRow(int $userId, string $date): ?UserCheckin
    {
        return UserCheckin::query()
            ->where('user_id', $userId)
            ->where('checkin_date', $date)
            ->first();
    }

    /** 严格早于该日期的最后一次签到 */
    private function lastBefore(int $userId, string $date): ?UserCheckin
    {
        return UserCheckin::query()
            ->where('user_id', $userId)
            ->where('checkin_date', '<', $date)
            ->orderByDesc('checkin_date')
            ->first();
    }

    /**
     * 当前有效连续天数：链还活着（最后一次签到是昨天）才算数，否则为 0
     */
    private function effectiveStreak(?UserCheckin $last, Carbon $today): int
    {
        if ($last === null) {
            return 0;
        }

        $lastDate = $last->checkin_date->toDateString();

        if ($lastDate === $today->toDateString()) {
            return (int) $last->streak;
        }

        return $this->isYesterday($last->checkin_date, $today) ? (int) $last->streak : 0;
    }

    private function totalDays(int $userId): int
    {
        return UserCheckin::query()->where('user_id', $userId)->count();
    }

    private function isYesterday(Carbon $candidate, Carbon $date): bool
    {
        return $candidate->toDateString() === $date->copy()->subDay()->toDateString();
    }

    private function isYesterdayFor(string $previousDate, Carbon $current): bool
    {
        return $previousDate === $current->copy()->subDay()->toDateString();
    }

    /** 归一化业务日期：签到只看「哪一天」，时分秒必须抹掉 */
    private function normalizeDate(?Carbon $date): Carbon
    {
        return $date === null ? Carbon::today() : $date->copy()->startOfDay();
    }

    private function parseDate(string $date): Carbon
    {
        if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            throw BusinessException::badRequest('日期格式必须为 Y-m-d');
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            throw BusinessException::badRequest('日期格式必须为 Y-m-d');
        }

        // 溢出回写比对：`2026-02-31` 会被 Carbon 静默滚到 3 月，只有回比才挡得住
        if ($parsed->toDateString() !== $date) {
            throw BusinessException::badRequest('日期格式必须为 Y-m-d');
        }

        return $parsed;
    }
}
