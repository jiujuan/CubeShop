<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 单号生成服务（架构文档 4.1.1 公共服务层）
 *
 * 规则：{前缀}{YYYYMMDD}{6 位日期内序列}，如 CS20260915000042
 * 实现：biz_no_sequences 表行内原子自增（行锁串行化），跨进程唯一，
 *       与数据库类型无关（PostgreSQL / SQLite 均可用），每日自动重置。
 */
class NoGeneratorService
{
    public const PREFIX_ORDER = 'CS';

    public const PREFIX_PAYMENT = 'PAY';

    public const PREFIX_REFUND = 'RF';

    /** 余额充值单（收银台方案 §4.1(4)：RC20260916000001） */
    public const PREFIX_RECHARGE = 'RC';

    public function generate(string $prefix): string
    {
        $date = now()->format('Ymd');

        $value = DB::transaction(function () use ($prefix, $date) {
            // 行锁串行化并发取号
            $row = DB::table('biz_no_sequences')
                ->where('biz_date', $date)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('biz_no_sequences')->insert([
                    'biz_date' => $date,
                    'prefix' => $prefix,
                    'current_value' => 1,
                ]);

                return 1;
            }

            $value = (int) $row->current_value + 1;
            DB::table('biz_no_sequences')
                ->where('id', $row->id)
                ->update(['current_value' => $value]);

            return $value;
        });

        return $prefix.$date.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    public function generateOrderNo(): string
    {
        return $this->generate(self::PREFIX_ORDER);
    }

    public function generatePaymentNo(): string
    {
        return $this->generate(self::PREFIX_PAYMENT);
    }

    public function generateRefundNo(): string
    {
        return $this->generate(self::PREFIX_REFUND);
    }

    public function generateRechargeNo(): string
    {
        return $this->generate(self::PREFIX_RECHARGE);
    }
}
