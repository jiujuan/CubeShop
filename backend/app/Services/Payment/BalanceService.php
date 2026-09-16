<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessException;
use App\Models\BalanceRecharge;
use App\Models\UserBalance;
use App\Models\UserBalanceLog;
use Illuminate\Support\Facades\DB;

/**
 * 余额账户服务（收银台方案 §6.5 / §7.4）
 *
 * 唯一入账/出账口：所有余额变动必须经本服务，保证 user_balance_logs 有且只有一条对应流水。
 *
 * 并发控制：行锁 lockForUpdate + version 乐观锁（SQLite 无行锁，由事务串行化兜底）。
 */
class BalanceService
{
    /**
     * 获取（不存在则创建）余额账户
     */
    public function account(int $userId, bool $lock = false): UserBalance
    {
        $query = UserBalance::query()->where('user_id', $userId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $account = $query->first();

        if ($account) {
            return $account;
        }

        return UserBalance::query()->create([
            'user_id' => $userId,
            'balance' => 0,
            'frozen' => 0,
            'total_recharge' => 0,
            'total_consume' => 0,
            'version' => 0,
        ]);
    }

    /** 可用余额 */
    public function balance(int $userId): string
    {
        return (string) $this->account($userId)->balance;
    }

    /**
     * 入账（正金额）
     *
     * @param  string|null  $totalRechargeDelta  计入「累计充值」的金额（默认与入账金额相同）；
     *                                           充值含赠送时传本金，使 total_recharge 只统计本金（§6.5）
     */
    public function credit(
        int $userId,
        string $amount,
        string $type = UserBalanceLog::TYPE_RECHARGE,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $remark = null,
        ?int $createdBy = null,
        ?string $totalRechargeDelta = null,
    ): UserBalanceLog {
        return DB::transaction(function () use ($userId, $amount, $type, $relatedType, $relatedId, $remark, $createdBy, $totalRechargeDelta) {
            $account = $this->account($userId, lock: true);
            $before = (string) $account->balance;
            $after = $this->add($before, $amount);

            $account->balance = $after;
            if ($type === UserBalanceLog::TYPE_RECHARGE) {
                $account->total_recharge = $this->add((string) $account->total_recharge, $totalRechargeDelta ?? $amount);
            }
            $account->version = (int) $account->version + 1;
            $account->save();

            return UserBalanceLog::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'remark' => $remark,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * 出账（负金额），余额不足抛 40010
     */
    public function debit(
        int $userId,
        string $amount,
        string $type = UserBalanceLog::TYPE_CONSUME,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $remark = null,
    ): UserBalanceLog {
        return DB::transaction(function () use ($userId, $amount, $type, $relatedType, $relatedId, $remark) {
            $account = $this->account($userId, lock: true);
            $before = (string) $account->balance;

            if (bccomp($before, $amount, 2) < 0) {
                throw BusinessException::badRequest('余额不足');
            }

            $after = $this->sub($before, $amount);
            $account->balance = $after;
            if ($type === UserBalanceLog::TYPE_CONSUME) {
                $account->total_consume = $this->add((string) $account->total_consume, $amount);
            }
            $account->version = (int) $account->version + 1;
            $account->save();

            return UserBalanceLog::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => '-'.ltrim($amount, '-'),
                'balance_before' => $before,
                'balance_after' => $after,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'remark' => $remark,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * 充值入账（唯一口，幂等）
     *
     * 幂等判据：user_balance_logs(related_type=recharge, related_id=充值单ID, type=recharge) 已存在则跳过，
     * 防止回调重发 / 重复核账导致重复加钱。
     */
    public function creditForRecharge(BalanceRecharge $recharge): UserBalanceLog
    {
        return DB::transaction(function () use ($recharge) {
            $existing = UserBalanceLog::query()
                ->where('related_type', 'recharge')
                ->where('related_id', $recharge->id)
                ->where('type', UserBalanceLog::TYPE_RECHARGE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $credit = bcadd((string) $recharge->amount, (string) $recharge->gift_amount, 2);
            $giftText = bccomp((string) $recharge->gift_amount, '0', 2) > 0
                ? "（含赠送 {$recharge->gift_amount}）"
                : '';

            return $this->credit(
                $recharge->user_id,
                $credit,
                UserBalanceLog::TYPE_RECHARGE,
                'recharge',
                $recharge->id,
                '余额充值 '.$recharge->recharge_no.$giftText,
                totalRechargeDelta: (string) $recharge->amount,
            );
        });
    }

    private function add(string $left, string $right): string
    {
        return bcadd($left, $right, 2);
    }

    private function sub(string $left, string $right): string
    {
        return bcsub($left, $right, 2);
    }
}
