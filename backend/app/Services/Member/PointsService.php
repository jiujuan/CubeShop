<?php

namespace App\Services\Member;

use App\Exceptions\BusinessException;
use App\Models\UserPoint;
use App\Models\UserPointLog;
use App\Support\Member\PointsRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 积分账户服务（会员成长计划 S1）
 *
 * 设计文档：docs/design/points-checkin-membership.md §5 / §6
 *
 * 积分变动的**唯一入口**：所有加减分必须经本服务，保证 user_point_logs 有且只有一条对应流水。
 * 体例照抄 BalanceService（账户行锁 + before/after + related_* 溯源）。
 *
 * ### 两个池子
 *
 * - balance 可用积分：能抵扣、能消耗的那部分
 * - frozen 冻结积分：S5 起下单占用产生，已从可用扣出但未确认消耗
 *
 * 冻结两段式（D1）：下单 freeze(可用→冻结) → 支付成功 confirm(冻结→消耗)
 * → 取消/超时/支付失败 release(冻结→可用)。S1 只提供这三个原语，S5 接线。
 *
 * ### 幂等
 *
 * 所有方法都可传 $bizKey（形如 `order:12:earn`）。命中已有 biz_key 流水时**直接返回旧流水**，
 * 不重复加减 —— 支付成功回调与退款回调都可能重放，这是防重复加分的唯一防线。
 * 依赖 user_point_logs.biz_key 唯一索引兜底并发。
 *
 * 并发控制：行锁 lockForUpdate（SQLite 无行锁，靠事务串行化兜底）+ version 乐观计数。
 */
class PointsService
{
    /**
     * 获取（不存在则创建）积分账户
     */
    public function account(int $userId, bool $lock = false): UserPoint
    {
        $query = UserPoint::query()->where('user_id', $userId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $account = $query->first();

        if ($account) {
            return $account;
        }

        return UserPoint::query()->create(['user_id' => $userId]);
    }

    /** 可用积分 */
    public function balance(int $userId): int
    {
        return (int) $this->account($userId)->balance;
    }

    /**
     * 账户概览（后台展示用：可用 / 冻结 / 累计获得 / 累计消耗）
     *
     * @return array{balance: int, frozen: int, total: int, total_earn: int, total_spend: int}
     */
    public function summary(int $userId): array
    {
        $account = $this->account($userId);

        return [
            'balance' => (int) $account->balance,
            'frozen' => (int) $account->frozen,
            'total' => (int) $account->balance + (int) $account->frozen,
            'total_earn' => (int) $account->total_earn,
            'total_spend' => (int) $account->total_spend,
        ];
    }

    /**
     * 入账（可用积分增加）
     *
     * @param  string  $type  见 PointsRules::TYPE_*
     * @param  string|null  $bizKey  幂等键，命中已有流水则直接返回之
     */
    public function credit(
        int $userId,
        int $points,
        string $type = PointsRules::TYPE_EARN,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $bizKey = null,
        ?string $remark = null,
        ?int $createdBy = null,
    ): UserPointLog {
        $this->assertPositive($points);

        return $this->move($userId, $points, 0, $type, $relatedType, $relatedId, $bizKey, $remark, $createdBy);
    }

    /**
     * 出账（可用积分减少），积分不足抛 40010
     */
    public function debit(
        int $userId,
        int $points,
        string $type = PointsRules::TYPE_CONSUME,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $bizKey = null,
        ?string $remark = null,
        ?int $createdBy = null,
    ): UserPointLog {
        $this->assertPositive($points);

        return $this->move($userId, -$points, 0, $type, $relatedType, $relatedId, $bizKey, $remark, $createdBy);
    }

    /**
     * 冻结：可用 → 冻结（S5 下单占用）
     */
    public function freeze(
        int $userId,
        int $points,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $bizKey = null,
        ?string $remark = null,
        ?int $createdBy = null,
    ): UserPointLog {
        $this->assertPositive($points);

        return $this->move($userId, -$points, $points, PointsRules::TYPE_FREEZE, $relatedType, $relatedId, $bizKey, $remark, $createdBy);
    }

    /**
     * 确认消耗：冻结 → 真实扣减（S5 支付成功）
     *
     * ⚠️ 只动冻结池，可用积分不变（下单时已扣过），故流水 points=0、frozen_points=−n。
     */
    public function confirm(
        int $userId,
        int $points,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $bizKey = null,
        ?string $remark = null,
        ?int $createdBy = null,
    ): UserPointLog {
        $this->assertPositive($points);

        return $this->move($userId, 0, -$points, PointsRules::TYPE_CONSUME, $relatedType, $relatedId, $bizKey, $remark, $createdBy);
    }

    /**
     * 释放：冻结 → 可用（S5 取消 / 超时 / 支付失败）
     */
    public function release(
        int $userId,
        int $points,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $bizKey = null,
        ?string $remark = null,
        ?int $createdBy = null,
    ): UserPointLog {
        $this->assertPositive($points);

        return $this->move($userId, $points, -$points, PointsRules::TYPE_RELEASE, $relatedType, $relatedId, $bizKey, $remark, $createdBy);
    }

    /**
     * 后台人工调整（正数加分 / 负数减分）
     *
     * 与 credit/debit 的区别：类型固定为 admin_adjust，related 指向用户本人，
     * reason 即流水备注（调用方保证非空），created_by 记管理员。
     */
    public function adjust(int $userId, int $points, string $reason, ?int $createdBy = null): UserPointLog
    {
        if ($points === 0) {
            throw BusinessException::badRequest('调整积分不能为 0');
        }

        if (abs($points) > PointsRules::ADJUST_MAX) {
            throw BusinessException::badRequest(sprintf('单次调整不得超过 %d 积分', PointsRules::ADJUST_MAX));
        }

        return $this->move(
            $userId,
            $points,
            0,
            PointsRules::TYPE_ADMIN_ADJUST,
            'user',
            $userId,
            null,
            $reason,
            $createdBy,
        );
    }

    /**
     * 最近流水（倒序）
     *
     * @return Collection<int, UserPointLog>
     */
    public function logs(int $userId, int $limit = 20): Collection
    {
        return UserPointLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 唯一的写入通道：同一事务内改账户 + 写流水
     *
     * @param  int  $pointsDelta  可用积分变动（带符号）
     * @param  int  $frozenDelta  冻结积分变动（带符号）
     */
    private function move(
        int $userId,
        int $pointsDelta,
        int $frozenDelta,
        string $type,
        ?string $relatedType,
        ?int $relatedId,
        ?string $bizKey,
        ?string $remark,
        ?int $createdBy,
    ): UserPointLog {
        return DB::transaction(function () use ($userId, $pointsDelta, $frozenDelta, $type, $relatedType, $relatedId, $bizKey, $remark, $createdBy) {
            // 幂等：同一业务键只生效一次（回调重放防重复加减）
            if ($bizKey !== null) {
                $existing = UserPointLog::query()->where('biz_key', $bizKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $account = $this->account($userId, lock: true);

            $balanceBefore = (int) $account->balance;
            $frozenBefore = (int) $account->frozen;
            $balanceAfter = $balanceBefore + $pointsDelta;
            $frozenAfter = $frozenBefore + $frozenDelta;

            if ($balanceAfter < 0) {
                throw BusinessException::badRequest('可用积分不足');
            }

            if ($frozenAfter < 0) {
                throw BusinessException::badRequest('冻结积分不足');
            }

            $account->balance = $balanceAfter;
            $account->frozen = $frozenAfter;

            $direction = PointsRules::directionOf($type, $pointsDelta, $frozenDelta);
            if ($direction === PointsRules::DIRECTION_EARN) {
                $account->total_earn = (int) $account->total_earn + abs($pointsDelta);
            } elseif ($direction === PointsRules::DIRECTION_SPEND) {
                // 抵扣确认属于「只减冻结」的情形，此时 points 变动为 0，量在 frozen 侧
                $account->total_spend = (int) $account->total_spend + abs($pointsDelta ?: $frozenDelta);
            }

            $account->version = (int) $account->version + 1;
            $account->save();

            return UserPointLog::create([
                'user_id' => $userId,
                'type' => $type,
                'points' => $pointsDelta,
                'frozen_points' => $frozenDelta,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'biz_key' => $bizKey,
                'remark' => $remark,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);
        });
    }

    private function assertPositive(int $points): void
    {
        if ($points <= 0) {
            throw BusinessException::badRequest('积分数量必须大于 0');
        }
    }
}
