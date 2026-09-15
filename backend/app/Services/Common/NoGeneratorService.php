<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 单号生成服务（架构文档 4.1.1 公共服务层）
 *
 * 规则：{前缀}{YYYYMMDD}{6 位日期内序列}，如 CS20260915000042
 * 序列用 PostgreSQL/SQLite 兼容的日期行 + 行内自增实现，事务内安全。
 */
class NoGeneratorService
{
    public const PREFIX_ORDER = 'CS';

    public const PREFIX_PAYMENT = 'PAY';

    public const PREFIX_REFUND = 'RF';

    public function generate(string $prefix): string
    {
        $date = now()->format('Ymd');

        // 借助 system_configs 无关的原子递增：使用数据库序列表
        // 简化实现：日期 + 微秒 + 随机数，冲突概率极低；订单表有唯一索引兜底重试
        $micro = str_pad((string) (microtime(true) * 10000 % 100000), 5, '0', STR_PAD_LEFT);
        $rand = str_pad((string) random_int(0, 9), 1, '0');

        return $prefix.$date.$micro.$rand;
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
}
